<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserAnswer;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;
use Illuminate\Support\Str;

class TryoutController extends Controller
{
    public function index(): JsonResponse
    {
        $tryouts = Tryout::with(['creator', 'tryoutSubtests.subtest'])
            ->withCount('userAccesses')
            ->latest()
            ->get();

        return response()->json([
            'data' => $tryouts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'category' => ['nullable', 'string', Rule::in(['UTBK', 'UM'])],
            'is_free' => ['nullable', 'boolean'],
            'use_irt' => ['nullable', 'boolean'],
            'randomize_options' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('tryout-images', 'public');
        }

        if ($request->end_date) {
            $validated['end_date'] = \Carbon\Carbon::parse($request->end_date)->startOfDay();
        }

        $validated['created_by'] = $request->user()->id;
        $validated['category'] = $validated['category'] ?? 'UTBK';
        $validated['is_free'] = $validated['is_free'] ?? false;
        $validated['use_irt'] = $validated['use_irt'] ?? true;
        $validated['randomize_options'] = $validated['randomize_options'] ?? false;
        $validated['is_published'] = $validated['is_published'] ?? false;

        $tryout = Tryout::create($validated);
        AuditLogger::log('Tryout', 'create', "Tryout dibuat: \"{$tryout->title}\"", $request->user(), $tryout);

        return response()->json([
            'message' => 'Tryout berhasil dibuat',
            'data' => $tryout,
        ], 201);
    }

    public function show(Tryout $tryout): JsonResponse
    {
        $tryout->load(['creator', 'tryoutSubtests.subtest'])
            ->loadCount('userAccesses');

        return response()->json([
            'data' => $tryout,
        ]);
    }

    public function participants(Request $request, Tryout $tryout): JsonResponse
    {
        $search = $request->query('search');
        $statusFilter = $request->query('status');

        $query = $tryout->userAccesses()->with('user')
            ->when($search, function ($q, $search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($statusFilter && $statusFilter !== 'all', function ($q) use ($statusFilter, $tryout) {
                if ($statusFilter === 'finished' || $statusFilter === 'in_progress') {
                    $q->whereHas('user', function ($uq) use ($tryout, $statusFilter) {
                        $uq->whereHas('tryoutSessions', function ($sq) use ($tryout, $statusFilter) {
                            $sq->where('tryout_id', $tryout->id)
                               ->where('status', $statusFilter)
                               ->whereRaw('tryout_sessions.attempt_number = (
                                    SELECT MAX(attempt_number) 
                                    FROM tryout_sessions ts 
                                    WHERE ts.user_id = users.id AND ts.tryout_id = ?
                               )', [$tryout->id]);
                        });
                    });
                } else if ($statusFilter === 'not_started') {
                    $q->whereDoesntHave('user.tryoutSessions', function ($sq) use ($tryout) {
                        $sq->where('tryout_id', $tryout->id);
                    });
                }
            });

        $paginated = $query->paginate($request->query('per_page', 15));

        $userIds = collect($paginated->items())->pluck('user_id');

        $sessions = \App\Models\TryoutSession::where('tryout_id', $tryout->id)
            ->whereIn('user_id', $userIds)
            ->orderBy('attempt_number', 'asc')
            ->get()
            ->keyBy('user_id');

        $paginated->getCollection()->transform(function ($access) use ($sessions) {
            $session = $sessions->get($access->user_id);
            if (!$session) {
                $status = 'not_started';
            } else {
                $status = $session->status; // 'finished' or 'in_progress'
            }

            $access->setAttribute('tryout_status', $status);

            $proofImages = collect($access->proof_images ?: ($access->proof_image ? [$access->proof_image] : []))
                ->filter()
                ->values();

            $access->setAttribute('proof_image_urls', $proofImages
                ->map(fn ($path) => asset(\Illuminate\Support\Facades\Storage::disk('public')->url($path)))
                ->all());

            return $access;
        });

        return response()->json([
            'data' => $paginated,
        ]);
    }

    public function update(Request $request, Tryout $tryout): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'category' => ['nullable', 'string', Rule::in(['UTBK', 'UM'])],
            'is_free' => ['nullable', 'boolean'],
            'use_irt' => ['nullable', 'boolean'],
            'randomize_options' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            if ($tryout->image && Storage::disk('public')->exists($tryout->image)) {
                Storage::disk('public')->delete($tryout->image);
            }
            $validated['image'] = $request->file('image')->store('tryout-images', 'public');
        }

        if ($request->end_date) {
            $validated['end_date'] = \Carbon\Carbon::parse($request->end_date)->startOfDay();
        }

        $validated['is_free'] = $validated['is_free'] ?? $tryout->is_free;
        $validated['use_irt'] = $validated['use_irt'] ?? $tryout->use_irt;
        $validated['randomize_options'] = $validated['randomize_options'] ?? $tryout->randomize_options;
        $validated['is_published'] = $validated['is_published'] ?? $tryout->is_published;
        $validated['category'] = $validated['category'] ?? $tryout->category ?? 'UTBK';

        $tryout->update($validated);
        AuditLogger::log('Tryout', 'update', "Tryout diupdate: \"{$tryout->title}\"", $request->user(), $tryout);

        return response()->json([
            'message' => 'Tryout berhasil diupdate',
            'data' => $tryout,
        ]);
    }

    public function destroy(Request $request, Tryout $tryout): JsonResponse
    {
        if ($tryout->image && Storage::disk('public')->exists($tryout->image)) {
            Storage::disk('public')->delete($tryout->image);
        }

        AuditLogger::log('Tryout', 'delete', "Tryout dihapus: \"{$tryout->title}\"", $request->user());
        $tryout->delete();

        return response()->json([
            'message' => 'Tryout berhasil dihapus',
        ]);
    }

    public function userReview(Request $request, Tryout $tryout, User $user): JsonResponse
    {
        $sessionQuery = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->where('status', 'finished');

        if ($request->filled('attempt')) {
            $sessionQuery->where('attempt_number', (int) $request->query('attempt'));
        }

        $session = $sessionQuery->latest('created_at')->first();

        if (! $session) {
            return response()->json(['message' => 'Session tryout tidak ditemukan untuk user ini'], 404);
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

        $data = $questions->map(function ($question) use ($userAnswers, $tryout, $session) {
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
                    
                    'discussion' => $question->discussion,
                    'discussion_image' => $question->discussion_image,
                    'discussion_image_url' => $question->discussion_image_url,
                    
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
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'attempt_number' => $session->attempt_number,
                'review' => $data,
            ],
        ]);
    }

    public function exportPdf(Tryout $tryout)
    {
        $tryout->load(['tryoutSubtests.subtest', 'tryoutSubtests.subtest.questions.options']);

        $subtests = $tryout->tryoutSubtests->map(function ($tryoutSubtest) {
            $questions = $tryoutSubtest->subtest->questions
                ->filter(fn ($q) => $q->is_active)
                ->sortBy('order_no')
                ->values();

            return [
                'name' => $tryoutSubtest->subtest->name,
                'duration' => $tryoutSubtest->duration_minutes,
                'questions' => $questions
            ];
        });

        $origin = request()->header('Origin') ?: '*';
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Expose-Headers: Content-Disposition');

        $pdf = PDF::loadView('pdf.tryout', [
            'tryout' => $tryout,
            'subtests' => $subtests,
        ], [], [
            'title' => 'Tryout ' . $tryout->title,
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 15,
            'margin_right' => 15,
            'watermark_image_path' => public_path('images/logo/amunisiptn-blue.png'),
            'watermark_image_alpha' => 0.08,
            'watermark_image_size' => 'D',
            'show_watermark_image' => true,
        ]);

        return $pdf->download('Tryout_' . Str::slug($tryout->title) . '.pdf');
    }
}
