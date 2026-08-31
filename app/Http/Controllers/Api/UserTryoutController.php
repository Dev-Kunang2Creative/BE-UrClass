<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketLog;
use App\Models\Tryout;
use App\Models\ProofRequirement;
use App\Models\Question;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\TryoutSubtestSession;
use App\Models\UserAnswer;
use App\Models\UserTryoutAccess;
use App\Models\Subtest;
use App\Services\ScoringService;
use App\Services\RankingService;
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
            ->where('kategori', $user->kategori ?? 'utbk')
            ->withCount('userAccesses')
            ->latest()
            ->get();

        $tryouts->each(function ($tryout) use ($accessByTryout, $sessionStatsByTryout, $sessionsByTryout) {
            $this->decorateUserState(
                $tryout,
                $accessByTryout->get($tryout->id),
                $sessionsByTryout->get($tryout->id),
                (int) ($sessionStatsByTryout->get($tryout->id)?->attempt_count ?? 0),
            );
        });

        return response()->json([
            'data' => $tryouts,
        ]);
    }

    /**
     * One tryout, shaped exactly like one item of index().
     *
     * There was no such endpoint, so the frontend fetched the whole list and
     * picked the id out of it on the client: every detail view downloaded every
     * tryout, and because that list is filtered by track, a tryout belonging to
     * the other jalur came back as "not found" rather than as what it is.
     */
    public function show(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        if (! $tryout->is_published) {
            return response()->json(['message' => 'Tryout ini tidak tersedia'], 404);
        }

        $userKategori = $user->kategori ?? 'utbk';

        // Not a 404: the tryout exists and the reader may well have meant to
        // open it. Saying which jalur it belongs to lets the client offer to
        // switch instead of claiming the thing does not exist.
        if (($tryout->kategori ?? 'utbk') !== $userKategori) {
            return response()->json([
                'message' => 'Tryout ini ada di jalur lain.',
                'kategori' => $tryout->kategori ?? 'utbk',
            ], 403);
        }

        $tryout->load([
            'creator',
            'tryoutSubtests.subtest' => fn ($query) => $query->withCount([
                'questions' => fn ($questionQuery) => $questionQuery->where('is_active', true),
            ]),
        ]);
        $tryout->loadCount('userAccesses');

        $access = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('created_at')
            ->first();

        $attemptCount = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->count();

        $this->decorateUserState($tryout, $access, $session, $attemptCount);

        return response()->json([
            'data' => $tryout,
        ]);
    }

    /**
     * The per-user attributes the client reads off a tryout. Shared by index()
     * and show() so a detail view can never disagree with the list it came
     * from about whether someone is enrolled or mid-attempt.
     */
    private function decorateUserState(
        Tryout $tryout,
        ?UserTryoutAccess $access,
        ?TryoutSession $session,
        int $attemptCount,
    ): void {
        $tryout->setAttribute('user_is_enrolled', (bool) $access);
        $tryout->setAttribute('user_attempt_count', $attemptCount);
        $tryout->setAttribute(
            'user_session_status',
            $session?->status ?? ($access ? 'not_started' : null)
        );
        $tryout->setAttribute('user_started_at', $session?->started_at);
        $tryout->setAttribute('user_finished_at', $session?->finished_at);
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
            
            // Satu unggahan untuk satu syarat, dan syaratnya diambil dari tabel
            // - bukan dipatok di sini. Dulu jumlahnya diturunkan dari jumlah akun
            // Instagram aktif, yang hanya masuk akal selama syaratnya memang
            // hanya follow. Sekarang syaratnya bisa follow, tag teman, atau
            // bagikan ke story, dan masing-masing punya slotnya sendiri.
            $requirements = ProofRequirement::active()->get();

            $rules = [];
            $messages = [];

            foreach ($requirements as $requirement) {
                $field = "proofs.{$requirement->id}";
                $rules[$field] = ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'];

                // Pesannya menyebut judul syaratnya, bukan nomor slot. Peserta
                // yang melewatkan satu unggahan perlu tahu yang mana, dan
                // "bukti ke-2" tidak menjawab itu.
                $messages["{$field}.required"] = "Bukti untuk \"{$requirement->title}\" wajib diunggah.";
                $messages["{$field}.image"] = "Bukti untuk \"{$requirement->title}\" harus berupa gambar.";
                $messages["{$field}.mimes"] = "Bukti untuk \"{$requirement->title}\" harus berformat jpeg, png, jpg, atau webp.";
                $messages["{$field}.max"] = "Bukti untuk \"{$requirement->title}\" maksimal 2MB.";
            }

            // Berkas yang akan dipakai, dari satu sumber saja. Divalidasi dan
            // disimpan dari variabel yang sama supaya tidak mungkin lolos
            // validasi lalu ternyata kosong saat disimpan.
            $uploaded = collect($request->file('proofs', []))->filter()->all();

            // Jalur mundur untuk tab yang masih memuat versi lama antarmuka.
            // Bentuk lamanya mengirim proof_images[] tanpa keterangan syarat, dan
            // urutannya satu-satunya petunjuk yang ada - jadi dipetakan berurutan
            // ke syarat aktif. Tanpa ini, siapa pun yang membuka dialog
            // pendaftaran sebelum deploy gagal mendaftar dengan pesan yang
            // menyebut slot yang tidak ada di layarnya.
            if ($uploaded === []) {
                $legacyFiles = collect($request->file('proof_images', []))->filter()->values();

                foreach ($requirements->values() as $index => $requirement) {
                    if ($file = $legacyFiles->get($index)) {
                        $uploaded[$requirement->id] = $file;
                    }
                }
            }

            $validator = Validator::make(['proofs' => $uploaded], $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $proofPaths = [];
            $proofDetails = [];

            foreach ($requirements as $requirement) {
                $file = $uploaded[$requirement->id] ?? null;

                if (! $file) {
                    continue;
                }

                $path = $file->store('proof-images', 'public');
                $proofPaths[] = $path;

                // Judulnya ikut disimpan, bukan hanya id-nya. Syarat bisa diubah
                // atau dihapus admin setelah pendaftaran masuk, dan bukti lama
                // tetap harus bisa dibaca apa maksudnya saat ditinjau.
                $proofDetails[] = [
                    'requirement_id' => $requirement->id,
                    'title' => $requirement->title,
                    'path' => $path,
                ];
            }

            DB::transaction(function () use ($user, $tryout, $proofPaths, $proofDetails) {
                UserTryoutAccess::create([
                    'user_id' => $user->id,
                    'tryout_id' => $tryout->id,
                    'proof_image' => $proofPaths[0] ?? null,
                    'proof_images' => $proofPaths,
                    'proof_details' => $proofDetails,
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

        // Filtered by track, like index() already was. Without this, every
        // attempt the user has ever made came back on both dashboards: someone
        // who had only ever sat an SKD tryout saw that score on the UTBK
        // dashboard, and saw it presented against the UTBK scale of 1000
        // instead of the 550 an SKD score is out of.
        $tryoutIds = UserTryoutAccess::where('user_id', $user->id)
            ->whereIn(
                'tryout_id',
                Tryout::where('kategori', $user->kategori ?? 'utbk')->select('id')
            )
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

        // Sesi yang belum selesai dilanjutkan, bukan diulang: percobaan itu sudah
        // dibayar dan orang yang kembali di tengah ujian tidak boleh ditagih lagi.
        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', '!=', 'finished')
            ->latest('created_at')
            ->first();

        $ticketBalanceRemaining = null;

        if (! $session) {
            $charged = DB::transaction(function () use ($user, $tryout, &$session, &$ticketBalanceRemaining) {
                // Dikunci lebih dulu, bukan hanya saat memotong tiket: kunci
                // inilah yang membuat dua permintaan start bersamaan tidak bisa
                // sama-sama membuat percobaan baru dan lolos satu pemotongan.
                $lockedUser = $user->newQuery()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedUser) {
                    return false;
                }

                // Dibaca setelah kunci didapat, supaya nomornya tidak basi.
                $existing = TryoutSession::where('user_id', $user->id)
                    ->where('tryout_id', $tryout->id)
                    ->where('status', '!=', 'finished')
                    ->latest('created_at')
                    ->first();

                if ($existing) {
                    $session = $existing;

                    return true;
                }

                $nextAttemptNumber = ((int) TryoutSession::where('user_id', $user->id)
                    ->where('tryout_id', $tryout->id)
                    ->max('attempt_number')) + 1;

                // Satu tiket untuk satu kali pengerjaan. Tiket yang dipotong
                // saat mendaftar hanya membayar percobaan pertama, jadi setiap
                // pengulangan tryout premium harus membayar lagi - kalau tidak,
                // satu tiket berlaku untuk percobaan tanpa batas.
                if ($nextAttemptNumber > 1 && ! $tryout->is_free) {
                    if ($lockedUser->ticket_balance <= 0) {
                        return false;
                    }

                    $lockedUser->decrement('ticket_balance', 1);

                    TicketLog::create([
                        'user_id'     => $lockedUser->id,
                        'type'        => 'debit',
                        'amount'      => 1,
                        'source'      => 'tryout',
                        'description' => 'Kerjakan ulang: ' . $tryout->title,
                    ]);

                    $ticketBalanceRemaining = $lockedUser->fresh()->ticket_balance;
                }

                $session = TryoutSession::create([
                    'user_id' => $user->id,
                    'tryout_id' => $tryout->id,
                    'attempt_number' => $nextAttemptNumber,
                    'started_at' => now(),
                    'status' => 'in_progress',
                ]);

                return true;
            });

            if (! $charged) {
                return response()->json([
                    'message' => 'Tiket tidak cukup untuk mengulang tryout ini. Satu tiket berlaku untuk satu kali pengerjaan.',
                ], 403);
            }
        }

        if ($session->status === 'not_started') {
            $session->update([
                'started_at' => now(),
                'status' => 'in_progress',
            ]);
            $session->refresh();
        }

        return response()->json([
            'message' => $session->attempt_number > 1 && ! $tryout->is_free
                ? 'Tryout dimulai. 1 Tiket telah digunakan.'
                : 'Tryout dimulai',
            'data' => $session,
            'ticket_balance_remaining' => $ticketBalanceRemaining,
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
            // BRD A-07: skor dihitung oleh ScoringService sesuai skema subtes
            // (irt / right_wrong / option_weight) agar tidak lagi hard-coded.
            $subtest = Subtest::find($question->subtest_id);

            if ($question->question_type === 'essay') {
                $isCorrect = true;
                $scoreValue = $subtest ? (float) ($subtest->score_correct ?? 1) : 1.0;
            } else {
                $scored = ScoringService::scoreAnswer(
                    $question,
                    $subtest ?: new Subtest(['name' => '', 'exam_type' => 'utbk']),
                    $answer
                );
                $isCorrect = $scored['is_correct'];
                $scoreValue = $scored['score'];
            }

            UserAnswer::updateOrCreate(
                [
                    'tryout_session_id' => $session->id,
                    'question_id' => $question->id,
                ],
                [
                    'answer' => $answer,
                    'is_correct' => $isCorrect,
                    'score' => $scoreValue,
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

        // Skor memakai jumlah nilai jawaban, bukan jumlah jawaban benar, supaya
        // bobot per opsi (TKP CPNS) ikut terhitung. Untuk subtes 1/0 hasilnya sama.
        $rawPoints = ScoringService::rawScoreForSession($session);
        $maxPoints = ScoringService::maxScoreForSession($session);
        $isCpns = $tryout->kategori === 'cpns';
        $simpleFinalScore = $isCpns ? $rawPoints : ($maxPoints > 0 ? ($rawPoints / $maxPoints) * 1000 : 0);
        $simpleMaxScore = $isCpns ? $maxPoints : 1000;

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
                'raw_score' => ! $tryout->use_irt ? round($rawPoints, 2) : 0,
                'final_score' => ! $tryout->use_irt ? round($simpleFinalScore, 2) : 0,
                'max_score' => ! $tryout->use_irt ? round($simpleMaxScore, 2) : 1000,
                'accuracy' => round($accuracy, 2),
            ],
            // Rincian per subtest: peserta CPNS dinilai per ambang, dan satu
            // subtest di bawah ambang membatalkan seluruh SKD. Agregat saja
            // menyembunyikan satu-satunya angka yang menentukan lulus.
            'per_subtest' => ScoringService::perSubtestBreakdown($session),
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
        // Populasi yang sama dengan yang dipakai leaderboard(): seluruh percobaan
        // yang selesai. Kalau kedua tempat memakai populasi berbeda, satu sesi
        // yang sama akan bernilai lain di halaman hasil dan di papan peringkat.
        $totalParticipants = TryoutSession::where('tryout_id', $tryout->id)
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

        // Skor sementara selama IRT belum final: proporsi jawaban benar terhadap
        // skor maksimum, diskalakan ke 1000 - rumus yang sama dengan tryout
        // non-IRT.
        //
        // Sengaja bukan angka IRT yang dihitung lebih awal. Bobot IRT berasal
        // dari seberapa banyak peserta lain menjawab benar, jadi saat pesertanya
        // masih segelintir bobotnya liar: satu-satunya peserta akan melihat tiap
        // soal yang ia salah dihargai sebelas kali lipat soal yang ia benar, dan
        // angkanya bisa melompat drastis begitu peserta lain masuk. Proporsi
        // jawaban benar tidak bergantung siapa pun, jadi tidak akan menyesatkan.
        $provisionalScore = round($simpleFinalScore, 2);

        $baseData['irt_result'] = [
            'is_ready' => $isIrtReady,
            'release_date' => $tryout->end_date,
            'total_participants_calculated' => $isIrtReady ? $totalParticipants : 0,
            'raw_score' => $isIrtReady ? round($rawIrtScore, 2) : 0,
            'final_score' => $isIrtReady ? round($finalScore1000, 2) : 0,
            'max_score' => 1000,
            'provisional_score' => $provisionalScore,
        ];
        $baseData['score_result'] = [
            'method' => $isIrtReady ? 'irt' : 'simple',
            'is_ready' => $isIrtReady,
            // Selama IRT belum final, yang dilaporkan adalah skor sementara -
            // bukan nol, yang dulu membuat halaman hasil seolah tidak punya
            // angka sama sekali.
            'is_provisional' => ! $isIrtReady,
            'raw_score' => $isIrtReady ? round($rawIrtScore, 2) : round($rawPoints, 2),
            'final_score' => $isIrtReady ? round($finalScore1000, 2) : $provisionalScore,
            'max_score' => $isIrtReady ? 1000 : round($simpleMaxScore, 2),
            'accuracy' => round($accuracy, 2),
        ];

        return response()->json([
            'message' => !$isIrtReady
                ? 'Skor sementara ditampilkan. Skor IRT final keluar setelah periode tryout berakhir.'
                : 'Sukses mengambil data IRT',
            'data' => $baseData,
        ]);
    }

    public function leaderboard(Request $request, Tryout $tryout): JsonResponse
    {
        // BRD P-10: ranking nasional / region / sekolah.
        // Default 'national' agar pemanggilan lama tetap kompatibel.
        $level = $request->query('level', RankingService::LEVEL_NATIONAL);
        if (! in_array($level, [
            RankingService::LEVEL_NATIONAL,
            RankingService::LEVEL_REGION,
            RankingService::LEVEL_SCHOOL,
        ], true)) {
            $level = RankingService::LEVEL_NATIONAL;
        }

        // Peringkat tidak lagi ditahan sampai periode tryout ditutup. Menahannya
        // membuat papan peringkat kosong tepat pada saat orang paling ingin
        // melihatnya - sesaat setelah selesai mengerjakan. Yang ditahan hanya
        // status "final"-nya: untuk tryout IRT, bobot tiap soal masih bergeser
        // selama peserta lain terus masuk, jadi peringkatnya sementara.
        $isFinal = RankingService::isPublishable($tryout);

        $subtestIds = TryoutSubtest::where('tryout_id', $tryout->id)->pluck('subtest_id');
        $totalQuestions = Question::whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->count();

        // Semua percobaan yang selesai ikut dihitung; yang dipakai untuk peringkat
        // nanti hanya percobaan terbaik tiap peserta.
        $sessionQuery = TryoutSession::with(['user', 'answers'])
            ->where('tryout_id', $tryout->id)
            ->where('status', 'finished');

        // Filter cakupan sesuai level yang diminta
        $viewer = $request->user();
        if ($level === RankingService::LEVEL_REGION) {
            $province = $request->query('region_province') ?? $viewer?->region_province;
            $sessionQuery->whereHas('user', function ($q) use ($province) {
                $province ? $q->where('region_province', $province)
                          : $q->whereNotNull('region_province');
            });
        } elseif ($level === RankingService::LEVEL_SCHOOL) {
            $schoolId = $request->query('school_id') ?? $viewer?->school_id;
            $sessionQuery->whereHas('user', function ($q) use ($schoolId) {
                $schoolId ? $q->where('school_id', $schoolId)
                          : $q->whereNotNull('school_id');
            });
        }

        $sessions = $sessionQuery->get();

        $includeProofImages = $request->user()?->role === 'admin';
        $proofsByUser = $includeProofImages
            ? UserTryoutAccess::where('tryout_id', $tryout->id)->get()->keyBy('user_id')
            : collect();

        $questionWeights = [];
        $totalWeightAll = 0;

        // Tingkat kesulitan soal adalah sifat soal terhadap seluruh peserta, bukan
        // terhadap satu provinsi atau satu sekolah. Penyebutnya dihitung dari
        // populasi yang sama dengan pembilangnya - sebelumnya pembilang memakai
        // seluruh peserta sementara penyebutnya memakai peserta yang lolos filter
        // level, sehingga p bisa melebihi 1 pada papan peringkat region/sekolah.
        $finishedSessionCount = TryoutSession::where('tryout_id', $tryout->id)
            ->where('status', 'finished')
            ->count();

        if ($tryout->use_irt && $finishedSessionCount > 0) {
            $allTryoutQuestions = Question::whereIn('subtest_id', $subtestIds)
                ->where('is_active', true)
                ->get();

            foreach ($allTryoutQuestions as $question) {
                $correctCount = UserAnswer::where('question_id', $question->id)
                    ->where('is_correct', true)
                    ->whereHas('tryoutSession', function ($query) use ($tryout) {
                        $query->where('tryout_id', $tryout->id)
                            ->where('status', 'finished');
                    })
                    ->count();

                $p = $correctCount / $finishedSessionCount;
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
                    $maxScore = 1000;
                } else {
                    $rawScore = ScoringService::rawScoreForSession($session);
                    $maxPoints = ScoringService::maxScoreForSession($session);
                    $isCpns = $tryout->kategori === 'cpns';
                    $finalScore = $isCpns ? $rawScore : ($maxPoints > 0 ? ($rawScore / $maxPoints) * 1000 : 0);
                    $maxScore = $isCpns ? $maxPoints : 1000;
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
                        'max_score' => round($maxScore, 2),
                    ],
                    'school_id' => $session->user?->school_id,
                    'school_name' => $session->user?->school_name,
                    'region_province' => $session->user?->region_province,
                    'region_city' => $session->user?->region_city,
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
            // Satu baris per peserta: percobaan dengan skor tertinggi. Peserta
            // yang mengulang tryout dinilai dari hasil terbaiknya, bukan dari
            // percobaan pertamanya, dan tidak muncul berkali-kali di papan.
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->sortBy([
                ['score.final_score', 'desc'],
                ['summary.correct', 'desc'],
                ['finished_at', 'asc'],
            ])->first())
            ->values()
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

        // Posisi peserta yang sedang melihat, pada level yang diminta
        $myRank = null;
        if ($viewer) {
            foreach ($leaderboard as $row) {
                if ((string) $row['user_id'] === (string) $viewer->id) {
                    $myRank = [
                        'rank' => $row['rank'],
                        'score' => $row['score']['final_score'],
                        'total_participants' => $leaderboard->count(),
                    ];
                    break;
                }
            }
        }

        return response()->json([
            'data' => [
                'tryout_id' => $tryout->id,
                'tryout_title' => $tryout->title,
                'use_irt' => $tryout->use_irt,
                'level' => $level,
                'is_ready' => true,
                // Peringkat sudah bisa dilihat sekarang; is_final menyatakan
                // apakah angkanya masih bisa bergeser.
                'is_final' => $isFinal,
                'release_date' => $tryout->end_date,
                'scope' => [
                    'region_province' => $request->query('region_province') ?? $viewer?->region_province,
                    'school_id' => $request->query('school_id') ?? $viewer?->school_id,
                ],
                'my_rank' => $myRank,
                'total_participants' => $leaderboard->count(),
                'leaderboard_basis' => 'best_attempt',
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
        $rawPoints = ScoringService::rawScoreForSession($session);
        $maxPoints = ScoringService::maxScoreForSession($session);
        $isCpns = $tryout->kategori === 'cpns';
        $finalScore = $isCpns ? $rawPoints : ($maxPoints > 0 ? ($rawPoints / $maxPoints) * 1000 : 0);

        return [
            'session_id' => $session->id,
            'tryout_id' => $tryout->id,
            'attempt_number' => $session->attempt_number,
            'status' => $session->status,
            'started_at' => $session->started_at,
            'finished_at' => $session->finished_at,
            'score' => [
                'raw_score' => round($rawPoints, 2),
                'final_score' => round($finalScore, 2),
                'max_score' => round($isCpns ? $maxPoints : 1000, 2),
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
