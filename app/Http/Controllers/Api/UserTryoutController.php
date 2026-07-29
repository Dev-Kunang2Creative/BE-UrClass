<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketLog;
use App\Models\Tryout;
use App\Models\Question;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\TryoutSubtestSession;
use App\Models\UserAnswer;
use App\Models\UserTryoutAccess;
use App\Support\RichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserTryoutController extends Controller
{
    public function index(): JsonResponse
    {
        $user = request()->user();

        $accessByTryout = UserTryoutAccess::where('user_id', $user->id)
            ->get()
            ->keyBy('tryout_id');

        $sessionStatsByTryout = TryoutSession::select(
                'tryout_id',
                DB::raw('COUNT(*) as attempt_count'),
                DB::raw('MAX(attempt_number) as latest_attempt_number')
            )
            ->where('user_id', $user->id)
            ->groupBy('tryout_id')
            ->get()
            ->keyBy('tryout_id');

        $sessionsByTryout = TryoutSession::where('user_id', $user->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('tryout_id')
            ->map(fn ($sessions) => $sessions->first());

        $tryouts = Tryout::with([
                'creator',
                'tryoutSubtests.subtest' => fn ($query) => $query->withCount([
                    'questions' => fn ($questionQuery) => $questionQuery->where('is_active', true),
                ]),
            ])
            ->where('is_published', true)
            ->withCount('userAccesses')
            ->latest()
            ->get();

        $tryouts->each(function ($tryout) use ($accessByTryout, $sessionStatsByTryout, $sessionsByTryout) {
            $access = $accessByTryout->get($tryout->id);
            $session = $sessionsByTryout->get($tryout->id);
            $sessionStats = $sessionStatsByTryout->get($tryout->id);

            $tryout->setAttribute('user_is_enrolled', (bool) $access);
            $tryout->setAttribute('user_attempt_count', (int) ($sessionStats?->attempt_count ?? 0));
            $tryout->setAttribute('user_session_status', $session?->status ?? ($access ? 'not_started' : null));
            $tryout->setAttribute('user_started_at', $session?->started_at);
            $tryout->setAttribute('user_finished_at', $session?->finished_at);
        });

        return response()->json([
            'data' => $tryouts,
        ]);
    }

    public function enroll(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        if (!$tryout->is_published) {
            return response()->json(['message' => 'Tryout ini tidak tersedia'], 404);
        }

        if (UserTryoutAccess::where('user_id', $user->id)->where('tryout_id', $tryout->id)->exists()) {
            return response()->json(['message' => 'Kamu sudah terdaftar di tryout ini'], 422);
        }

        // --- JIKA TRYOUT GRATIS ---
        if ($tryout->is_free) {
            
            $validator = Validator::make($request->all(), [
                'proof_images' => ['required', 'array', 'min:2', 'max:5'],
                'proof_images.*' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            ], [
                'proof_images.required' => 'Bukti follow Instagram wajib diunggah untuk mengikuti tryout gratis.',
                'proof_images.array' => 'Bukti follow harus dikirim sebagai daftar gambar.',
                'proof_images.min' => 'Minimal unggah 2 bukti follow Instagram.',
                'proof_images.max' => 'Maksimal unggah 5 bukti follow Instagram.',
                'proof_images.*.required' => 'Setiap bukti follow wajib berupa gambar.',
                'proof_images.*.image' => 'Setiap bukti harus berupa gambar.',
                'proof_images.*.mimes' => 'Format gambar harus jpeg, png, jpg, atau webp.',
                'proof_images.*.max' => 'Ukuran setiap gambar maksimal 2MB.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $proofPaths = collect($request->file('proof_images', []))
                ->map(fn ($file) => $file->store('proof-images', 'public'))
                ->values()
                ->all();

            DB::transaction(function () use ($user, $tryout, $proofPaths) {
                UserTryoutAccess::create([
                    'user_id' => $user->id,
                    'tryout_id' => $tryout->id,
                    'proof_image' => $proofPaths[0] ?? null,
                    'proof_images' => $proofPaths,
                    'granted_at' => now(),
                ]);
            });

            return response()->json([
                'message' => 'Berhasil mendaftar tryout gratis.',
                'participants_count' => $tryout->userAccesses()->count(),
            ]);
        }
        
        // --- JIKA TRYOUT PREMIUM ---
        else {
            $ticketBalanceRemaining = DB::transaction(function () use ($user, $tryout) {
                $lockedUser = $user->newQuery()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedUser || $lockedUser->ticket_balance <= 0) {
                    return null;
                }

                $lockedUser->decrement('ticket_balance', 1);

                UserTryoutAccess::create([
                    'user_id' => $lockedUser->id,
                    'tryout_id' => $tryout->id,
                    'granted_at' => now(),
                ]);

                TicketLog::create([
                    'user_id'     => $lockedUser->id,
                    'type'        => 'debit',
                    'amount'      => 1,
                    'source'      => 'tryout',
                    'description' => $tryout->title,
                ]);

                return $lockedUser->fresh()->ticket_balance;
            });

            if ($ticketBalanceRemaining === null) {
                return response()->json(['message' => 'Tiket tidak cukup. Silakan beli paket tiket terlebih dahulu.'], 403);
            }

            return response()->json([
                'message' => 'Berhasil mendaftar tryout. 1 Tiket telah digunakan.',
                'ticket_balance_remaining' => $ticketBalanceRemaining,
                'participants_count' => $tryout->userAccesses()->count(),
            ]);
        }
    }

    public function myTryouts(Request $request): JsonResponse
    {
        $user = $request->user();

        $tryoutIds = UserTryoutAccess::where('user_id', $user->id)
            ->pluck('tryout_id');

        $sessionsByTryout = TryoutSession::where('user_id', $user->id)
            ->whereIn('tryout_id', $tryoutIds)
            ->orderByDesc('attempt_number')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('tryout_id')
            ->map(fn ($sessions) => $sessions->first());

        $sessionCountsByTryout = TryoutSession::where('user_id', $user->id)
            ->whereIn('tryout_id', $tryoutIds)
            ->select('tryout_id', DB::raw('COUNT(*) as attempt_count'))
            ->groupBy('tryout_id')
            ->get()
            ->pluck('attempt_count', 'tryout_id');

        $tryouts = Tryout::with([
                'tryoutSubtests.subtest' => fn ($query) => $query->withCount([
                    'questions' => fn ($questionQuery) => $questionQuery->where('is_active', true),
                ]),
            ])
            ->whereIn('id', $tryoutIds)
            ->where('is_published', true)
            ->get();

        $finishedSessionsByTryout = TryoutSession::with('answers')
            ->where('user_id', $user->id)
            ->whereIn('tryout_id', $tryoutIds)
            ->where('status', 'finished')
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('tryout_id');

        $tryouts->each(function ($tryout) use ($user, $sessionsByTryout, $sessionCountsByTryout, $finishedSessionsByTryout) {
            $session = $sessionsByTryout->get($tryout->id);
            $subtestIds = $tryout->tryoutSubtests->pluck('subtest_id');
            $totalQuestions = Question::whereIn('subtest_id', $subtestIds)
                ->where('is_active', true)
                ->count();

            $tryout->setAttribute('user_is_enrolled', true);
            $tryout->setAttribute('user_attempt_count', (int) ($sessionCountsByTryout->get($tryout->id) ?? 0));
            $tryout->setAttribute('user_session_status', $session?->status ?? 'not_started');
            $tryout->setAttribute('user_started_at', $session?->started_at);
            $tryout->setAttribute('user_finished_at', $session?->finished_at);
            $tryout->setAttribute(
                'user_attempts',
                ($finishedSessionsByTryout->get($tryout->id) ?? collect())
                    ->map(fn ($attempt) => $this->formatAttemptHistory($tryout, $attempt, $totalQuestions))
                    ->values()
            );

            $shuffledSubtests = $tryout->tryoutSubtests->sortBy(function ($subtest) use ($user) {
                return md5($user->id . $subtest->id);
            })->values();

            $shuffledSubtests->each(function ($subtest, $index) {
                $subtest->order_no = $index + 1;
            });

            $tryout->setRelation('tryoutSubtests', $shuffledSubtests);
        });

        return response()->json([
            'data' => $tryouts,
        ]);
    }

    public function start(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $hasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Kamu tidak punya akses ke tryout ini. Silakan daftar menggunakan tiket.',
            ], 403);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', '!=', 'finished')
            ->latest('created_at')
            ->first();

        if (! $session) {
            $nextAttemptNumber = ((int) TryoutSession::where('user_id', $user->id)
                ->where('tryout_id', $tryout->id)
                ->max('attempt_number')) + 1;

            $session = TryoutSession::create([
                'user_id' => $user->id,
                'tryout_id' => $tryout->id,
                'attempt_number' => $nextAttemptNumber,
                'started_at' => now(),
                'status' => 'in_progress',
            ]);
        }

        if ($session->status === 'not_started') {
            $session->update([
                'started_at' => now(),
                'status' => 'in_progress',
            ]);
            $session->refresh();
        }

        return response()->json([
            'message' => 'Tryout dimulai',
            'data' => $session,
        ]);
    }

    public function startSubtest(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        $user = $request->user();

        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json(['message' => 'Data tryout subtest tidak cocok'], 404);
        }

        $hasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json(['message' => 'Kamu tidak punya akses ke tryout ini'], 403);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', '!=', 'finished')
            ->latest('created_at')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Tryout belum dimulai'], 422);
        }

        $subtestSession = TryoutSubtestSession::firstOrCreate(
            [
                'tryout_session_id' => $session->id,
                'tryout_subtest_id' => $tryoutSubtest->id,
            ],
            [
                'started_at' => now(),
                'status' => 'in_progress',
            ]
        );

        $endTime = $subtestSession->started_at
            ? $subtestSession->started_at->copy()->addMinutes($tryoutSubtest->duration_minutes)
            : null;

        $remainingSeconds = $endTime
            ? max((int) ceil(now()->diffInSeconds($endTime, false)), 0)
            : 0;

        if ($remainingSeconds <= 0 && $subtestSession->status === 'in_progress') {
            $subtestSession->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);

            $subtestSession->refresh();
        }

        return response()->json([
            'message' => 'Subtest dimulai',
            'data' => [
                'subtest_session_id' => $subtestSession->id,
                'started_at' => $subtestSession->started_at,
                'end_time' => $endTime,
                'remaining_seconds' => $remainingSeconds,
                'status' => $subtestSession->status,
            ],
        ]);
    }

    public function showSubtestQuestions(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        $user = $request->user();

        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json(['message' => 'Data tryout subtest tidak cocok'], 404);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', '!=', 'finished')
            ->latest('created_at')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Tryout belum dimulai'], 422);
        }

        $subtestSession = TryoutSubtestSession::where('tryout_session_id', $session->id)
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->first();

        if (! $subtestSession) {
            return response()->json(['message' => 'Subtest belum dimulai'], 422);
        }

        $endTime = $subtestSession->started_at
            ? $subtestSession->started_at->copy()->addMinutes($tryoutSubtest->duration_minutes)
            : null;

        $remainingSeconds = $endTime
            ? max((int) ceil(now()->diffInSeconds($endTime, false)), 0)
            : 0;

        if ($remainingSeconds <= 0 && $subtestSession->status === 'in_progress') {
            $subtestSession->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);
            $subtestSession->refresh();

            return response()->json([
                'message' => 'Waktu subtest sudah habis',
                'data' => [
                    'timer' => [
                        'started_at' => $subtestSession->started_at,
                        'end_time' => $endTime,
                        'remaining_seconds' => 0,
                        'status' => $subtestSession->status,
                    ],
                ],
            ], 422);
        }

        $cacheKey = "tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions";
        $questionsData = Cache::remember($cacheKey, 3600, function () use ($tryoutSubtest) {
            // Menggunakan Question yang terhubung ke subtest_id
            return Question::with(['options'])
                ->where('subtest_id', $tryoutSubtest->subtest_id)
                ->where('is_active', true)
                ->get();
        });

        $questionsData = $questionsData->sortBy(function ($item) use ($session) {
            return md5($session->id . $item->id);
        })->values();

        $userAnswers = UserAnswer::where('tryout_session_id', $session->id)
            ->pluck('answer', 'question_id');

        $questions = $questionsData->map(function ($question, $index) use ($userAnswers, $session, $tryout) {
            $myAnswer = $userAnswers[$question->id] ?? null;

            $options = $question->question_type === 'multiple_choice' && $tryout->randomize_options
                ? $question->options->sortBy(function ($option) use ($session, $question) {
                    return md5($session->id . $question->id . $option->id);
                })->values()
                : $question->options->values();

            return [
                'id' => $question->id,
                'question_type' => $question->question_type,
                'question_text' => $question->question_text,
                'question_image' => $question->question_image,
                'question_image_url' => $question->question_image_url,
                'order_no' => $index + 1,
                'options' => $options->map(function ($option) {
                    return [
                        'id' => $option->id,
                        'option_key' => $option->option_key,
                        'option_text' => $option->option_text,
                    ];
                })->values(),
                'my_answer' => $myAnswer,
            ];
        })->values();

        return response()->json([
            'data' => [
                'tryout' => [
                    'id' => $tryout->id,
                    'title' => $tryout->title,
                ],
                'subtest' => [
                    'id' => $tryoutSubtest->id,
                    'name' => $tryoutSubtest->subtest->name,
                    'duration_minutes' => $tryoutSubtest->duration_minutes,
                ],
                'timer' => [
                    'started_at' => $subtestSession->started_at,
                    'end_time' => $endTime,
                    'remaining_seconds' => $remainingSeconds,
                    'status' => $subtestSession->status,
                ],
                'questions' => $questions,
            ],
        ]);
    }

    public function submitAnswer(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest, Question $question): JsonResponse
    {
        $user = $request->user();

        if (
            $tryoutSubtest->tryout_id !== $tryout->id ||
            $question->subtest_id !== $tryoutSubtest->subtest_id
        ) {
            return response()->json(['message' => 'Data soal tidak cocok'], 404);
        }

        $validated = $request->validate([
            'answer' => ['nullable', 'string'],
        ]);

        if ($question->question_type === 'multiple_choice') {
            Validator::make($validated, [
                'answer' => ['nullable', 'string', 'in:A,B,C,D,E'],
            ])->validate();
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', '!=', 'finished')
            ->latest('created_at')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Sesi tidak valid'], 422);
        }

        $answer = $validated['answer'] ?? null;
        if ($question->question_type === 'essay' && $answer !== null) {
            $answer = RichTextSanitizer::sanitize($answer);
        }

        if ($answer && trim(strip_tags($answer)) !== '') {
            $correctAnswer = $question->correct_answer ?? null;
            $isCorrect = $question->question_type === 'essay'
                ? true
                : $answer === $correctAnswer;

            UserAnswer::updateOrCreate(
                [
                    'tryout_session_id' => $session->id,
                    'question_id' => $question->id,
                ],
                [
                    'answer' => $answer,
                    'is_correct' => $isCorrect,
                    'answered_at' => now(),
                ]
            );
        } else {
            UserAnswer::where('tryout_session_id', $session->id)
                ->where('question_id', $question->id)
                ->delete();
        }

        return response()->json([
            'message' => 'Jawaban berhasil disimpan',
            'data' => [
                'question_id' => $question->id,
                'answer' => $answer
            ],
        ]);
    }

    public function finishSubtest(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        $user = $request->user();

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', '!=', 'finished')
            ->latest('created_at')
            ->first();

        $subtestSession = TryoutSubtestSession::where('tryout_session_id', $session->id ?? '')
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->first();

        if (! $subtestSession) return response()->json(['message' => 'Subtest belum dimulai'], 422);

        if (!in_array($subtestSession->status, ['finished', 'expired'])) {
            $subtestSession->update([
                'status' => 'finished',
                'finished_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Subtest berhasil diselesaikan',
            'data' => $subtestSession->fresh(),
        ]);
    }

    public function finish(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();
        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', '!=', 'finished')
            ->latest('created_at')
            ->first();

        if (! $session) return response()->json(['message' => 'Tryout belum dimulai'], 422);

        if ($session->status !== 'finished') {
            $session->update([
                'status' => 'finished',
                'finished_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Tryout selesai',
            'data' => $session->fresh(),
        ]);
    }

    public function result(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $sessionQuery = TryoutSession::with(['answers'])
            ->where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', 'finished');

        if ($request->filled('attempt')) {
            $sessionQuery->where('attempt_number', (int) $request->query('attempt'));
        }

        $session = $sessionQuery->latest('created_at')->first();

        if (! $session && ! $request->filled('attempt')) {
            $session = TryoutSession::with(['answers'])
                ->where('user_id', $user->id)
                ->where('tryout_id', $tryout->id)
                ->latest('created_at')
                ->first();
        }

        if (! $session) {
            return response()->json(['message' => 'Session tryout tidak ditemukan'], 404);
        }

        // Cari subtest apa saja yang ada di Tryout ini
        $subtestIds = TryoutSubtest::where('tryout_id', $tryout->id)->pluck('subtest_id');

        $totalQuestions = Question::whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->count();

        $answered = $session->answers()->whereNotNull('answer')->count();
        $correct = $session->answers()->where('is_correct', true)->count();
        $wrong = $session->answers()->where('is_correct', false)->count();
        $unanswered = max($totalQuestions - $answered, 0);
        $accuracy = $totalQuestions > 0 ? ($correct / $totalQuestions) * 100 : 0;
        $simpleFinalScore = $totalQuestions > 0 ? ($correct / $totalQuestions) * 1000 : 0;

        $baseData = [
            'tryout_id' => $tryout->id,
            'tryout_title' => $tryout->title,
            'use_irt' => $tryout->use_irt,
            'attempt_number' => $session->attempt_number,
            'status' => $session->status,
            'started_at' => $session->started_at,
            'finished_at' => $session->finished_at,
            'summary' => [
                'total_questions' => $totalQuestions,
                'answered' => $answered,
                'correct' => $correct,
                'wrong' => $wrong,
                'unanswered' => $unanswered,
            ],
            'score_result' => [
                'method' => $tryout->use_irt ? 'irt' : 'simple',
                'is_ready' => ! $tryout->use_irt,
                'raw_score' => ! $tryout->use_irt ? $correct : 0,
                'final_score' => ! $tryout->use_irt ? round($simpleFinalScore, 2) : 0,
                'accuracy' => round($accuracy, 2),
            ],
            'irt_result' => null,
        ];

        if (!$tryout->use_irt) {
            return response()->json([
                'message' => 'Hasil tryout berhasil diambil.',
                'data' => $baseData,
            ]);
        }

        $now = now();
        $isIrtReady = !($tryout->end_date && $now->lt($tryout->end_date));

        $rawIrtScore = 0;
        $finalScore1000 = 0;
        $totalParticipants = TryoutSession::where('tryout_id', $tryout->id)
            ->where('attempt_number', 1)
            ->where('status', 'finished')
            ->count();

        if ($isIrtReady && $totalParticipants > 0) {
            $allTryoutQuestions = Question::whereIn('subtest_id', $subtestIds)
                ->where('is_active', true)
                ->get();

            $totalWeightAll = 0;
            $questionStats = [];

            foreach ($allTryoutQuestions as $q) {
                $correctCount = UserAnswer::where('question_id', $q->id)
                    ->where('is_correct', true)
                    ->whereHas('tryoutSession', function ($query) use ($tryout) {
                        $query->where('tryout_id', $tryout->id)
                            ->where('attempt_number', 1)
                            ->where('status', 'finished');
                    })
                    ->count();

                $p = $correctCount / $totalParticipants;
                $safeP = $p <= 0 ? 0.0001 : ($p >= 1 ? 0.9999 : $p);
                $weight = max(1, log((1 - $safeP) / $safeP) + 2);

                $questionStats[$q->id] = $weight;
                $totalWeightAll += $weight;
            }

            foreach ($session->answers as $answer) {
                if ($answer->is_correct && isset($questionStats[$answer->question_id])) {
                    $rawIrtScore += $questionStats[$answer->question_id];
                }
            }

            $finalScore1000 = ($totalWeightAll > 0) ? ($rawIrtScore / $totalWeightAll) * 1000 : 0;
        }

        $baseData['irt_result'] = [
            'is_ready' => $isIrtReady,
            'release_date' => $tryout->end_date,
            'total_participants_calculated' => $isIrtReady ? $totalParticipants : 0,
            'raw_score' => $isIrtReady ? round($rawIrtScore, 2) : 0,
            'final_score' => $isIrtReady ? round($finalScore1000, 2) : 0,
        ];
        $baseData['score_result'] = [
            'method' => 'irt',
            'is_ready' => $isIrtReady,
            'raw_score' => $isIrtReady ? round($rawIrtScore, 2) : 0,
            'final_score' => $isIrtReady ? round($finalScore1000, 2) : 0,
            'accuracy' => round($accuracy, 2),
        ];

        return response()->json([
            'message' => !$isIrtReady ? 'Hasil IRT sedang dalam proses dan akan keluar setelah periode tryout berakhir.' : 'Sukses mengambil data IRT',
            'data' => $baseData,
        ]);
    }

    public function leaderboard(Request $request, Tryout $tryout): JsonResponse
    {
        $subtestIds = TryoutSubtest::where('tryout_id', $tryout->id)->pluck('subtest_id');
        $totalQuestions = Question::whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->count();

        $sessions = TryoutSession::with(['user', 'answers'])
            ->where('tryout_id', $tryout->id)
            ->where('attempt_number', 1)
            ->where('status', 'finished')
            ->get();

        $includeProofImages = $request->user()?->role === 'admin';
        $proofsByUser = $includeProofImages
            ? UserTryoutAccess::where('tryout_id', $tryout->id)->get()->keyBy('user_id')
            : collect();

        $questionWeights = [];
        $totalWeightAll = 0;

        if ($tryout->use_irt && $sessions->isNotEmpty()) {
            $allTryoutQuestions = Question::whereIn('subtest_id', $subtestIds)
                ->where('is_active', true)
                ->get();

            foreach ($allTryoutQuestions as $question) {
                $correctCount = UserAnswer::where('question_id', $question->id)
                    ->where('is_correct', true)
                    ->whereHas('tryoutSession', function ($query) use ($tryout) {
                        $query->where('tryout_id', $tryout->id)
                            ->where('attempt_number', 1)
                            ->where('status', 'finished');
                    })
                    ->count();

                $p = $correctCount / $sessions->count();
                $safeP = $p <= 0 ? 0.0001 : ($p >= 1 ? 0.9999 : $p);
                $weight = max(1, log((1 - $safeP) / $safeP) + 2);

                $questionWeights[$question->id] = $weight;
                $totalWeightAll += $weight;
            }
        }

        $leaderboard = $sessions
            ->map(function ($session) use ($totalQuestions, $tryout, $questionWeights, $totalWeightAll, $includeProofImages, $proofsByUser) {
                $answered = $session->answers->whereNotNull('answer')->count();
                $correct = $session->answers->where('is_correct', true)->count();
                $wrong = $session->answers->where('is_correct', false)->count();
                $unanswered = max($totalQuestions - $answered, 0);
                $accuracy = $totalQuestions > 0 ? ($correct / $totalQuestions) * 100 : 0;

                if ($tryout->use_irt && $totalWeightAll > 0) {
                    $rawScore = $session->answers
                        ->where('is_correct', true)
                        ->sum(fn ($answer) => $questionWeights[$answer->question_id] ?? 0);
                    $finalScore = ($rawScore / $totalWeightAll) * 1000;
                } else {
                    $rawScore = $correct;
                    $finalScore = $totalQuestions > 0 ? ($correct / $totalQuestions) * 1000 : 0;
                }

                $row = [
                    'user_id' => $session->user_id,
                    'user_name' => $session->user?->name ?? 'Peserta',
                    'attempt_number' => $session->attempt_number,
                    'started_at' => $session->started_at,
                    'finished_at' => $session->finished_at,
                    'summary' => [
                        'total_questions' => $totalQuestions,
                        'answered' => $answered,
                        'correct' => $correct,
                        'wrong' => $wrong,
                        'unanswered' => $unanswered,
                        'accuracy' => round($accuracy, 2),
                    ],
                    'score' => [
                        'raw_score' => round($rawScore, 2),
                        'final_score' => round($finalScore, 2),
                    ],
                ];

                if ($includeProofImages) {
                    $access = $proofsByUser->get($session->user_id);
                    $proofImages = collect($access?->proof_images ?: ($access?->proof_image ? [$access->proof_image] : []))
                        ->filter()
                        ->values();

                    $row['proof_images'] = $proofImages->all();
                    $row['proof_image_urls'] = $proofImages
                        ->map(fn ($path) => asset(Storage::disk('public')->url($path)))
                        ->all();
                }

                return $row;
            })
            ->sortBy([
                ['score.final_score', 'desc'],
                ['summary.correct', 'desc'],
                ['finished_at', 'asc'],
            ])
            ->values()
            ->map(function ($row, $index) {
                $row['rank'] = $index + 1;

                return $row;
            });

        return response()->json([
            'data' => [
                'tryout_id' => $tryout->id,
                'tryout_title' => $tryout->title,
                'use_irt' => $tryout->use_irt,
                'leaderboard_basis' => 'attempt_number_1',
                'leaderboard' => $leaderboard,
            ],
        ]);
    }

    public function unlockDiscussion(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $access = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $access) {
            return response()->json(['message' => 'Akses tryout tidak ditemukan'], 404);
        }

        if ($access->discussion_unlocked || !$tryout->is_free) {
            return response()->json(['message' => 'Pembahasan sudah terbuka'], 422);
        }

        $success = DB::transaction(function () use ($user, $access) {
            $lockedUser = $user->newQuery()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedUser || $lockedUser->ticket_balance <= 0) {
                return false;
            }

            $lockedUser->decrement('ticket_balance', 1);
            $access->update(['discussion_unlocked' => true]);

            return true;
        });

        if (! $success) {
            return response()->json(['message' => 'Tiket tidak cukup. Silakan beli paket tiket terlebih dahulu.'], 403);
        }

        return response()->json([
            'message' => 'Pembahasan berhasil dibuka. 1 Tiket telah digunakan.',
            'discussion_unlocked' => true
        ]);
    }

    public function review(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $sessionQuery = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', 'finished');

        if ($request->filled('attempt')) {
            $sessionQuery->where('attempt_number', (int) $request->query('attempt'));
        }

        $session = $sessionQuery->latest('created_at')->first();

        if (! $session) {
            return response()->json(['message' => 'Session tryout tidak ditemukan'], 404);
        }

        if ($session->status !== 'finished') {
            return response()->json(['message' => 'Review hanya bisa diakses setelah tryout selesai'], 422);
        }

        $subtestIds = TryoutSubtest::where('tryout_id', $tryout->id)->pluck('subtest_id');

        $questions = Question::with(['options', 'subtest'])
            ->whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->orderBy('order_no')
            ->get();

        $userAnswers = UserAnswer::where('tryout_session_id', $session->id)
            ->get()
            ->keyBy('question_id');

        $access = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        $isUnlocked = !$tryout->is_free || ($access && $access->discussion_unlocked);

        $data = $questions->map(function ($question) use ($userAnswers, $tryout, $isUnlocked, $session) {
            $answer = $userAnswers->get($question->id);
            $options = $question->question_type === 'multiple_choice' && $tryout->randomize_options
                ? $question->options->sortBy(function ($option) use ($session, $question) {
                    return md5($session->id . $question->id . $option->id);
                })->values()
                : $question->options->values();

            return [
                'question_id' => $question->id,
                'subtest' => [
                    'id' => $question->subtest->id,
                    'name' => $question->subtest->name,
                ],
                'question' => [
                    'id' => $question->id,
                    'question_type' => $question->question_type,
                    'question_text' => $question->question_text,
                    'question_image' => $question->question_image,
                    'question_image_url' => $question->question_image_url,
                    
                    'discussion' => $isUnlocked ? $question->discussion : '(Gunakan 1 Tiket untuk pembahasan)',
                    'discussion_image' => $isUnlocked ? $question->discussion_image : null,
                    'discussion_image_url' => $isUnlocked ? $question->discussion_image_url : null,
                    
                    'correct_answer' => $question->correct_answer,
                    'options' => $options->map(function ($option) {
                        return [
                            'id' => $option->id,
                            'option_key' => $option->option_key,
                            'option_text' => $option->option_text,
                        ];
                    })->values(),
                ],
                'my_answer' => $answer?->answer,
                'is_correct' => $answer?->is_correct,
            ];
        })->values();

        return response()->json([
            'data' => [
                'tryout_id' => $tryout->id,
                'tryout_title' => $tryout->title,
                'attempt_number' => $session->attempt_number,
                'review' => $data,
            ],
        ]);
    }

    private function formatAttemptHistory(Tryout $tryout, TryoutSession $session, int $totalQuestions): array
    {
        $correct = $session->answers->where('is_correct', true)->count();
        $answered = $session->answers->whereNotNull('answer')->count();
        $wrong = $session->answers->where('is_correct', false)->count();
        $accuracy = $totalQuestions > 0 ? ($correct / $totalQuestions) * 100 : 0;
        $finalScore = $totalQuestions > 0 ? ($correct / $totalQuestions) * 1000 : 0;

        return [
            'session_id' => $session->id,
            'tryout_id' => $tryout->id,
            'attempt_number' => $session->attempt_number,
            'status' => $session->status,
            'started_at' => $session->started_at,
            'finished_at' => $session->finished_at,
            'score' => [
                'raw_score' => $correct,
                'final_score' => round($finalScore, 2),
                'accuracy' => round($accuracy, 2),
            ],
            'summary' => [
                'total_questions' => $totalQuestions,
                'answered' => $answered,
                'correct' => $correct,
                'wrong' => $wrong,
                'unanswered' => max($totalQuestions - $answered, 0),
            ],
        ];
    }
}
