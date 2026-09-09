<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Services\ScoringService;
use App\Support\RichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    public function index(Subtest $subtest): JsonResponse
    {
        $questions = Question::with('options')
            ->withCount('userAnswers')
            ->where('subtest_id', $subtest->id)
            ->orderBy('order_no')
            ->orderBy('id')
            ->get();

        $scheme = ScoringService::schemeFor($subtest);

        // Soal TKP yang bobotnya belum digarap tetap menghasilkan angka, hanya
        // angka yang salah, jadi tanpa penanda ini kesalahannya tidak terlihat
        // di mana pun sampai peserta melihat nilainya sendiri.
        if ($scheme === ScoringService::SCHEME_OPTION_WEIGHT) {
            $questions->each(function (Question $question) {
                $question->setAttribute(
                    'needs_option_weight',
                    ScoringService::validateOptionWeights(
                        $question->options->pluck('score')->all(),
                    ) !== null,
                );
            });
        }

        return response()->json([
            'data' => $questions,
            'meta' => [
                'scoring_scheme' => $scheme,
                'needs_option_weight_count' => $questions
                    ->where('needs_option_weight', true)
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request, Subtest $subtest): JsonResponse
    {
        if ($subtest->max_questions > 0) {
            $currentQuestionCount = Question::where('subtest_id', $subtest->id)->count();
            if ($currentQuestionCount >= $subtest->max_questions) {
                return response()->json([
                    'message' => 'Tidak dapat menambahkan soal karena jumlah soal sudah mencapai batas maksimal (' . $subtest->max_questions . ').'
                ], 422);
            }
        }

        $validated = $request->validate([
            'question_type' => ['nullable', 'string', Rule::in(['multiple_choice', 'essay'])],
            'question_text' => ['nullable', 'string'],
            'question_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'discussion' => ['nullable', 'string'],
            'discussion_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'correct_answer' => ['nullable', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'order_no' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            
            'options' => ['nullable', 'array'],
            'options.*.option_key' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'options.*.option_text' => ['nullable', 'string'],
            'options.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'options.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $validated['question_type'] = $validated['question_type'] ?? 'multiple_choice';
        $validated['options'] = $validated['options'] ?? [];

        $weighted = $validated['question_type'] === 'multiple_choice'
            && ScoringService::schemeFor($subtest) === ScoringService::SCHEME_OPTION_WEIGHT;

        if ($validated['question_type'] === 'multiple_choice') {
            if (count($validated['options']) < 2) {
                return response()->json(['message' => 'Minimal 2 opsi jawaban harus diisi.'], 422);
            }

            // Subtes berbobot per opsi (TKP SKD) tidak punya kunci jawaban:
            // kelima opsi bernilai, dari 1 sampai 5. Yang dicatat sebagai
            // "benar" adalah opsi berbobot tertinggi, diturunkan dari bobotnya
            // supaya kunci dan bobot tidak mungkin saling bertentangan.
            if ($weighted) {
                $weightError = ScoringService::validateOptionWeights(
                    collect($validated['options'])->pluck('score')->all(),
                );

                if ($weightError !== null) {
                    return response()->json(['message' => $weightError], 422);
                }

                $validated['correct_answer'] = collect($validated['options'])
                    ->sortByDesc(fn ($option) => (float) $option['score'])
                    ->first()['option_key'];
            }

            if (empty($validated['correct_answer'])) {
                return response()->json(['message' => 'Jawaban benar wajib diisi untuk soal pilihan ganda.'], 422);
            }

            $optionKeys = collect($validated['options'])->pluck('option_key');
            if ($optionKeys->count() !== $optionKeys->unique()->count()) {
                return response()->json(['message' => 'Setiap pilihan jawaban harus memiliki huruf yang berbeda (A, B, C, D, atau E).'], 422);
            }

            if (! $optionKeys->contains($validated['correct_answer'])) {
                return response()->json(['message' => 'Jawaban benar harus sesuai dengan salah satu pilihan jawaban.'], 422);
            }
        }

        $question = DB::transaction(function () use ($request, $validated, $subtest) {
            // Upload Gambar Soal & Diskusi
            $qImage = $request->hasFile('question_image') ? $request->file('question_image')->store('question-images', 'public') : null;
            $dImage = $request->hasFile('discussion_image') ? $request->file('discussion_image')->store('discussion-images', 'public') : null;

            $question = Question::create([
                'subtest_id' => $subtest->id,
                'question_type' => $validated['question_type'],
                'question_text' => RichTextSanitizer::sanitize($validated['question_text'] ?? null),
                'question_image' => $qImage,
                'discussion' => RichTextSanitizer::sanitize($validated['discussion'] ?? null),
                'discussion_image' => $dImage,
                'correct_answer' => $validated['question_type'] === 'essay' ? null : $validated['correct_answer'],
                'order_no' => $validated['order_no'] ?? (Question::where('subtest_id', $subtest->id)->max('order_no') ?? 0) + 1,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Upload Gambar di Opsi (Jika Ada)
            foreach ($validated['question_type'] === 'multiple_choice' ? $validated['options'] : [] as $index => $option) {
                $optImage = null;
                if ($request->hasFile("options.{$index}.image")) {
                    $optImage = $request->file("options.{$index}.image")->store('option-images', 'public');
                }

                $isCorrect = isset($validated['correct_answer'])
                    && strcasecmp(trim($option['option_key']), trim((string) $validated['correct_answer'])) === 0;

                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key' => $option['option_key'],
                    'option_text' => RichTextSanitizer::sanitize($option['option_text'] ?? null),
                    'image' => $optImage,
                    // Per-option weight (TKP). Falls back to right/wrong credit.
                    'score' => $option['score'] ?? ($isCorrect ? 1 : 0),
                    'is_correct' => $isCorrect,
                ]);
            }

            return $question->load('options');
        });

        return response()->json([
            'message' => 'Soal berhasil dibuat',
            'data' => $question,
        ], 201);
    }

    public function show(Subtest $subtest, Question $question): JsonResponse
    {
        if ($question->subtest_id !== $subtest->id) {
            return response()->json(['message' => 'Soal tidak ditemukan pada subtest ini.'], 404);
        }

        $question->load('options')->loadCount('userAnswers');

        return response()->json([
            'data' => $question,
        ]);
    }

    public function update(Request $request, Subtest $subtest, Question $question): JsonResponse
    {
        if ($question->subtest_id !== $subtest->id) {
            return response()->json(['message' => 'Soal tidak ditemukan pada subtest ini.'], 404);
        }

        $validated = $request->validate([
            'question_type' => ['nullable', 'string', Rule::in(['multiple_choice', 'essay'])],
            'question_text' => ['nullable', 'string'],
            'question_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'discussion' => ['nullable', 'string'],
            'discussion_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'correct_answer' => ['nullable', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            
            'options' => ['nullable', 'array'],
            'options.*.option_key' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'options.*.option_text' => ['nullable', 'string'],
            'options.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'options.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'delete_question_image' => ['nullable', 'boolean'],
            'delete_discussion_image' => ['nullable', 'boolean'],
        ]);

        $validated['question_type'] = $validated['question_type'] ?? 'multiple_choice';
        $validated['options'] = $validated['options'] ?? [];

        $weighted = $validated['question_type'] === 'multiple_choice'
            && ScoringService::schemeFor($subtest) === ScoringService::SCHEME_OPTION_WEIGHT;

        if ($validated['question_type'] === 'multiple_choice') {
            if (count($validated['options']) < 2) {
                return response()->json(['message' => 'Minimal 2 opsi jawaban harus diisi.'], 422);
            }

            // Subtes berbobot per opsi (TKP SKD) tidak punya kunci jawaban:
            // kelima opsi bernilai, dari 1 sampai 5. Yang dicatat sebagai
            // "benar" adalah opsi berbobot tertinggi, diturunkan dari bobotnya
            // supaya kunci dan bobot tidak mungkin saling bertentangan.
            if ($weighted) {
                $weightError = ScoringService::validateOptionWeights(
                    collect($validated['options'])->pluck('score')->all(),
                );

                if ($weightError !== null) {
                    return response()->json(['message' => $weightError], 422);
                }

                $validated['correct_answer'] = collect($validated['options'])
                    ->sortByDesc(fn ($option) => (float) $option['score'])
                    ->first()['option_key'];
            }

            if (empty($validated['correct_answer'])) {
                return response()->json(['message' => 'Jawaban benar wajib diisi untuk soal pilihan ganda.'], 422);
            }

            $optionKeys = collect($validated['options'])->pluck('option_key');
            if ($optionKeys->count() !== $optionKeys->unique()->count()) {
                return response()->json(['message' => 'Setiap pilihan jawaban harus memiliki huruf yang berbeda (A, B, C, D, atau E).'], 422);
            }

            if (! $optionKeys->contains($validated['correct_answer'])) {
                return response()->json(['message' => 'Jawaban benar harus sesuai dengan salah satu pilihan jawaban.'], 422);
            }
        }

        $question = DB::transaction(function () use ($request, $validated, $question) {
            $qImage = $question->question_image;
            if ($request->boolean('delete_question_image') && $qImage) {
                Storage::disk('public')->delete($qImage);
                $qImage = null;
            }
            if ($request->hasFile('question_image')) {
                if ($qImage) Storage::disk('public')->delete($qImage);
                $qImage = $request->file('question_image')->store('question-images', 'public');
            }

            $dImage = $question->discussion_image;
            if ($request->boolean('delete_discussion_image') && $dImage) {
                Storage::disk('public')->delete($dImage);
                $dImage = null;
            }
            if ($request->hasFile('discussion_image')) {
                if ($dImage) Storage::disk('public')->delete($dImage);
                $dImage = $request->file('discussion_image')->store('discussion-images', 'public');
            }

            $question->update([
                'question_type' => $validated['question_type'],
                'question_text' => RichTextSanitizer::sanitize($validated['question_text'] ?? null),
                'question_image' => $qImage,
                'discussion' => RichTextSanitizer::sanitize($validated['discussion'] ?? null),
                'discussion_image' => $dImage,
                'correct_answer' => $validated['question_type'] === 'essay' ? null : $validated['correct_answer'],
                'order_no' => $validated['order_no'],
                'is_active' => $validated['is_active'],
            ]);

            $oldOptions = $question->options->keyBy('option_key');
            $question->options()->delete();

            foreach ($validated['question_type'] === 'multiple_choice' ? $validated['options'] : [] as $index => $option) {
                $optKey = $option['option_key'];
                $oldImage = $oldOptions->has($optKey) ? $oldOptions[$optKey]->image : null;
                $optImage = $oldImage;

                if ($request->hasFile("options.{$index}.image")) {
                    if ($oldImage) Storage::disk('public')->delete($oldImage);
                    $optImage = $request->file("options.{$index}.image")->store('option-images', 'public');
                }

                $isCorrect = isset($validated['correct_answer'])
                    && strcasecmp(trim($optKey), trim((string) $validated['correct_answer'])) === 0;

                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key' => $optKey,
                    'option_text' => RichTextSanitizer::sanitize($option['option_text'] ?? null),
                    'image' => $optImage,
                    // Per-option weight (TKP). Falls back to right/wrong credit.
                    'score' => $option['score'] ?? ($isCorrect ? 1 : 0),
                    'is_correct' => $isCorrect,
                ]);
            }

            return $question->load('options');
        });

        return response()->json([
            'message' => 'Soal berhasil diupdate',
            'data' => $question,
        ]);
    }

    public function destroy(Subtest $subtest, Question $question): JsonResponse
    {
        if ($question->subtest_id !== $subtest->id) {
            return response()->json(['message' => 'Soal tidak ditemukan pada subtest ini.'], 404);
        }

        $usageCount = $question->userAnswers()->count();

        if ($usageCount > 0) {
            return response()->json([
                'message' => "Soal tidak dapat dihapus karena sudah terhubung ke {$usageCount} riwayat jawaban peserta. Nonaktifkan soal jika tidak ingin digunakan lagi.",
                'data' => [
                    'user_answers_count' => $usageCount,
                ],
            ], 422);
        }

        $imagePaths = collect([
            $question->question_image,
            $question->discussion_image,
            ...$question->options()->pluck('image')->all(),
        ])->filter()->values()->all();

        $question->delete();

        // File tidak ikut terhapus oleh soft delete/database cascade. Hapus
        // setelah record berhasil dihapus agar storage tidak dipenuhi orphan.
        if ($imagePaths !== []) {
            Storage::disk('public')->delete($imagePaths);
        }

        return response()->json([
            'message' => 'Soal berhasil dihapus',
        ]);
    }
}
