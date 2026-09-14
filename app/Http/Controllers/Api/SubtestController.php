<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subtest;
use App\Services\AuditLogger;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

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

    public function exportPdf(Subtest $subtest)
    {
        $subtest->load(['questions' => function ($q) {
            $q->where('is_active', true)->orderBy('order_no');
        }, 'questions.options']);

        $questions = $subtest->questions;

        foreach ($questions as $q) {
            if ($q->question_image && Storage::disk('public')->exists($q->question_image)) {
                $raw = Storage::disk('public')->get($q->question_image);
                $mime = Storage::disk('public')->mimeType($q->question_image) ?: 'image/jpeg';
                $q->question_image_base64 = 'data:' . $mime . ';base64,' . base64_encode($raw);
            }
        }

        $logoPath = public_path('images/logo/urclass.png');
        $logoBase64 = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;

        $pdf = PDF::loadView('pdf.subtest', [
            'subtest'    => $subtest,
            'questions'  => $questions,
            'logoBase64' => $logoBase64,
        ], [], [
            'title'                => 'Naskah Soal ' . $subtest->name,
            'margin_top'           => 26,
            'margin_bottom'        => 22,
            'margin_left'          => 16,
            'margin_right'         => 16,
            'margin_header'        => 12,
            'margin_footer'        => 12,
            'show_watermark_image' => false,
            'show_watermark'       => false,
        ]);

        $filename = 'Naskah_Soal_' . Str::slug($subtest->name) . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function exportExcel(Subtest $subtest): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $subtest->load(['questions' => function ($q) {
            $q->where('is_active', true)->orderBy('order_no');
        }, 'questions.options']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lembar Soal');

        $headers = ['No', 'Pertanyaan / Soal', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E'];
        $sheet->fromArray($headers, null, 'A1');

        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF004AAB'],
            ],
            'alignment' => [
                'vertical'   => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $rowNum = 2;
        foreach ($subtest->questions as $index => $q) {
            $optionsMap = [];
            foreach ($q->options as $opt) {
                $teks = trim(strip_tags(html_entity_decode($opt->option_text ?? '', ENT_QUOTES, 'UTF-8')));

                // Opsi boleh bergambar tanpa teks sama sekali. Sel kosong akan
                // terbaca sebagai opsi yang belum diisi, padahal isinya ada -
                // hanya saja tidak berbentuk teks yang bisa masuk ke sel.
                if ($teks === '' && $opt->image) {
                    $teks = '[gambar]';
                } elseif ($opt->image) {
                    $teks .= ' [+gambar]';
                }

                $optionsMap[strtoupper($opt->option_key)] = $teks;
            }

            $cleanQuestionText = trim(strip_tags(html_entity_decode($q->question_text ?? '', ENT_QUOTES, 'UTF-8')));

            $sheet->setCellValue('A' . $rowNum, $index + 1);
            $sheet->setCellValue('B' . $rowNum, $cleanQuestionText);
            $sheet->setCellValue('C' . $rowNum, $optionsMap['A'] ?? '');
            $sheet->setCellValue('D' . $rowNum, $optionsMap['B'] ?? '');
            $sheet->setCellValue('E' . $rowNum, $optionsMap['C'] ?? '');
            $sheet->setCellValue('F' . $rowNum, $optionsMap['D'] ?? '');
            $sheet->setCellValue('G' . $rowNum, $optionsMap['E'] ?? '');

            $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->getAlignment()->setWrapText(true);
            $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

            $rowNum++;
        }

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(26);
        $sheet->getColumnDimension('D')->setWidth(26);
        $sheet->getColumnDimension('E')->setWidth(26);
        $sheet->getColumnDimension('F')->setWidth(26);
        $sheet->getColumnDimension('G')->setWidth(26);

        $writer = new Xlsx($spreadsheet);
        $filename = sprintf('soal-%s-%s.xlsx', Str::slug($subtest->name), now()->format('Y-m-d'));

        return response()->stream(
            fn() => $writer->save('php://output'),
            200,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]
        );
    }
}