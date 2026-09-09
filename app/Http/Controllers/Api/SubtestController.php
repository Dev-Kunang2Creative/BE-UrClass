<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subtest;
use App\Services\AuditLogger;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubtestController extends Controller
{
    public function index(): JsonResponse
    {
        $subtests = Subtest::orderBy('category')
            ->orderBy('id')
            ->get();

            return response()->json([
                'data' => $subtests,
            ]);
    }

    public function store(Request $request): JsonResponse
    {
        $examType = $request->input('exam_type');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:subtests,name'],
            'exam_type' => ['required', 'in:utbk,cpns'],
            'category' => [
                'required',
                'string',
                Rule::exists('subtest_categories', 'code')
                    ->where(fn ($query) => $query->where('exam_type', $examType)->where('is_active', true)),
            ],
            'max_questions' => ['required', 'integer', 'min:1'],
            'scoring_scheme' => ['nullable', Rule::in([
                ScoringService::SCHEME_IRT,
                ScoringService::SCHEME_RIGHT_WRONG,
                ScoringService::SCHEME_OPTION_WEIGHT,
            ])],
            'score_correct' => ['nullable', 'numeric', 'between:-100,100'],
            'score_wrong'   => ['nullable', 'numeric', 'between:-100,100'],
            'score_empty'   => ['nullable', 'numeric', 'between:-100,100'],
        ]);

        // Kolom scoring_scheme punya default 'right_wrong' di database, jadi
        // tanpa langkah ini subtes TKP yang dibuat lewat panel admin akan
        // dinilai benar/salah selamanya: schemeFor() hanya jatuh ke default
        // ketika nilai tersimpannya tidak sah, dan 'right_wrong' itu sah.
        $subtest = new Subtest($validated);

        self::applyScoringScheme($subtest, $validated);
        $subtest->save();
        AuditLogger::log('Subtest', 'create', "Subtest dibuat: \"{$subtest->name}\"", $request->user(), $subtest);

        return response()->json([
            'message' => 'Subtest created successfully',
            'subtest' => $subtest,
        ], 201);
    }

    public function update(Request $request, Subtest $subtest): JsonResponse
    {
        $examType = $request->input('exam_type', $subtest->exam_type);

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255', 'unique:subtests,name,' . $subtest->id],
            'exam_type'     => ['required', 'in:utbk,cpns'],
            'category'      => [
                'required',
                'string',
                Rule::exists('subtest_categories', 'code')
                    ->where(fn ($query) => $query->where('exam_type', $examType)->where('is_active', true)),
            ],
            'max_questions' => ['sometimes', 'integer', 'min:0'],
            'scoring_scheme' => ['nullable', Rule::in([
                ScoringService::SCHEME_IRT,
                ScoringService::SCHEME_RIGHT_WRONG,
                ScoringService::SCHEME_OPTION_WEIGHT,
            ])],
            'score_correct' => ['nullable', 'numeric', 'between:-100,100'],
            'score_wrong'   => ['nullable', 'numeric', 'between:-100,100'],
            'score_empty'   => ['nullable', 'numeric', 'between:-100,100'],
        ]);

        // null berarti "tidak dikirim", bukan "kosongkan": tanpa ini form lama
        // yang belum mengirim field skor akan menimpanya dengan null.
        $validated = array_filter(
            $validated,
            fn ($value, $key) => ! in_array($key, ['scoring_scheme', 'score_correct', 'score_wrong', 'score_empty'], true)
                || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );

        $subtest->fill($validated);
        self::applyScoringScheme($subtest, $validated);
        $validated = [];

        $subtest->save();
        AuditLogger::log('Subtest', 'update', "Subtest diupdate: \"{$subtest->name}\"", $request->user(), $subtest);

        return response()->json([
            'message' => 'Subtest updated successfully',
            'subtest' => $subtest,
        ]);
    }

    /**
     * Skema penilaian mengikuti jalur ujiannya, bukan sekadar apa yang dikirim.
     *
     * UTBK dikunci ke IRT: di skema itu tidak ada poin benar/salah yang perlu
     * ditetapkan, jadi angka apa pun yang ikut terkirim untuk subtes UTBK
     * dikembalikan ke nol supaya tidak ada nilai yang tersimpan tapi tak
     * terpakai. CPNS bebas memilih benar/salah atau bobot per opsi.
     */
    private static function applyScoringScheme(Subtest $subtest, array $validated): void
    {
        if ($subtest->exam_type === 'utbk') {
            $subtest->scoring_scheme = ScoringService::SCHEME_IRT;
            $subtest->score_correct = 1;
            $subtest->score_wrong = 0;
            $subtest->score_empty = 0;

            return;
        }

        if (empty($validated['scoring_scheme'])
            || $validated['scoring_scheme'] === ScoringService::SCHEME_IRT) {
            $subtest->scoring_scheme = ScoringService::defaultSchemeFor($subtest);
        }

        // SKD menilai satu soal TWK/TIU sebesar 5, bukan 1. Default kolomnya 1,
        // yang membuat nilai TWK sempurna berhenti di 30 sementara ambang
        // lulusnya 65.
        if (! isset($validated['score_correct'])
            && $subtest->scoring_scheme === ScoringService::SCHEME_RIGHT_WRONG) {
            $subtest->score_correct = ScoringService::CPNS_SCORE_CORRECT;
        }
    }

    public function destroy(Request $request, Subtest $subtest): JsonResponse
    {
        AuditLogger::log('Subtest', 'delete', "Subtest dihapus: \"{$subtest->name}\"", $request->user());
        $subtest->delete();

        return response()->json([
            'message' => 'Subtest deleted successfully',
        ]);
    }

    public function show(Subtest $subtest): JsonResponse
    {
        $subtest->loadCount('questions');

        return response()->json([
            'data' => $subtest,
        ]);
    }
}