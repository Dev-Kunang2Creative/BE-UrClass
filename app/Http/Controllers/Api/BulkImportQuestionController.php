<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Services\AuditLogger;
use App\Services\ScoringService;
use App\Support\RichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\RichText\Run;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class BulkImportQuestionController extends Controller
{
    // Kolom XLSX: Gambar | Soal | Opsi A-E | Kunci Jawaban | Pembahasan | Gambar Pembahasan | Skor A-E
    //
    // Lima kolom Skor A-E hanya dipakai subtes berskema option_weight (TKP SKD),
    // yang di sana wajib diisi 1-5 dan menggantikan kolom Kunci Jawaban.

    private const ALLOWED_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];

    public function store(Request $request, Subtest $subtest): JsonResponse
    {
        // Hanya Excel. Format CSV dihapus supaya cuma ada satu format yang perlu
        // dijaga - CSV tidak bisa membawa gambar maupun bobot per opsi, jadi
        // dua jalur impor berarti dua tingkat kelengkapan yang berbeda.
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'file.mimes' => 'File harus berformat Excel (.xlsx atau .xls).',
        ]);

        [$imported, $skipped, $errors] = $this->importFromExcel($request->file('file'), $subtest);

        if ($imported > 0) {
            AuditLogger::log(
                'Question', 'bulk_import',
                "Import {$imported} soal ke subtest \"{$subtest->name}\"" . ($skipped > 0 ? ", {$skipped} baris dilewati" : ''),
                $request->user(), $subtest
            );
        }

        return response()->json([
            'message'  => "{$imported} soal berhasil diimpor." . ($skipped > 0 ? " {$skipped} baris dilewati." : ''),
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ], $imported > 0 ? 201 : 422);
    }

    // -------------------------------------------------------------------------
    // Excel Import (format baru dengan kolom Gambar Pembahasan)
    // Kolom: Gambar | Soal | Opsi A | Opsi B | Opsi C | Opsi D | Opsi E | Kunci | Pembahasan | Gambar Pembahasan
    // -------------------------------------------------------------------------
    private function importFromExcel(UploadedFile $file, Subtest $subtest): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet       = $spreadsheet->getActiveSheet();

        // Ambil semua baris sebagai array (1-indexed), iterasi hingga kolom O
        $allRows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', 'O') as $cell) {
                $cells[] = $this->cellToHtml($cell);
            }
            $allRows[$row->getRowIndex()] = $cells;
        }

        if (empty($allRows)) {
            return [0, 0, ['File Excel kosong.']];
        }

        // Deteksi header (row pertama berisi teks header)
        $firstRow  = reset($allRows);
        $firstKey  = array_key_first($allRows);
        $isHeader  = stripos($firstRow[1] ?? '', 'soal') !== false
                  || stripos($firstRow[0] ?? '', 'gambar') !== false;

        $hasDiscussionImageCol = false;
        $discussionImageColLetter = 'J';

        if ($isHeader && is_array($firstRow)) {
            foreach ($firstRow as $idx => $headerText) {
                $cleaned = strtolower(strip_tags($headerText));
                if (str_contains($cleaned, 'gambar pembahasan') || str_contains($cleaned, 'ilustrasi pembahasan')) {
                    $hasDiscussionImageCol = true;
                    $discussionImageColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
                    break;
                }
            }
            unset($allRows[$firstKey]);
        } else {
            // Jika file tanpa baris header, default anggap ada kolom Gambar Pembahasan jika kolom J bukan angka skor
            $hasDiscussionImageCol = true;
        }

        // Bangun map: rowNumber → Drawing (dipisah antara gambar soal dan gambar pembahasan)
        $questionImagesByRow = [];
        $discussionImagesByRow = [];

        foreach ($sheet->getDrawingCollection() as $drawing) {
            $coords = $drawing->getCoordinates();
            if (preg_match('/^([A-Z]+)(\d+)$/i', $coords, $m)) {
                $col = strtoupper($m[1]);
                $row = (int) $m[2];

                if ($col === 'A') {
                    $questionImagesByRow[$row] = $drawing;
                } elseif ($col === $discussionImageColLetter || ($hasDiscussionImageCol && $col === 'J')) {
                    $discussionImagesByRow[$row] = $drawing;
                }
            }
        }

        $errors   = [];
        $imported = 0;
        $skipped  = 0;
        $weighted = ScoringService::schemeFor($subtest) === ScoringService::SCHEME_OPTION_WEIGHT;
        $maxQ     = $subtest->max_questions;
        $currentQ = Question::where('subtest_id', $subtest->id)->count();
        $startNo  = $currentQ + 1;

        // Kolom skor: K-O (index 10-14) pada template baru, atau J-N (index 9-13) pada template lama
        $scoreIndices = $hasDiscussionImageCol
            ? ['A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14]
            : ['A' => 9,  'B' => 10, 'C' => 11, 'D' => 12, 'E' => 13];

        foreach ($allRows as $rowNum => $cells) {
            $lineNo = $rowNum;

            if (empty(array_filter($cells))) continue;

            $questionText  = $cells[1] ?? '';
            $answerA       = $cells[2] ?? '';
            $answerB       = $cells[3] ?? '';
            $answerC       = $cells[4] ?? '';
            $answerD       = $cells[5] ?? '';
            $answerE       = $cells[6] ?? '';
            $correctAnswer = strtoupper(strip_tags($cells[7] ?? ''));
            $discussion    = $cells[8] ?? '';

            // Kosong berarti "tidak diisi", bukan nol - nol bukan
            // nilai yang sah pada skema bobot per opsi.
            $optionScores = [];
            foreach ($scoreIndices as $key => $index) {
                $raw = trim(strip_tags($cells[$index] ?? ''));
                $optionScores[$key] = $raw === '' ? null : $raw;
            }

            // Subtes berbobot tidak punya soal esai, dan kolom Kunci Jawabannya
            // memang kosong: kuncinya diturunkan dari bobot tertinggi.
            $questionType = $weighted
                ? 'multiple_choice'
                : (trim($correctAnswer) === '' ? 'essay' : 'multiple_choice');

            // Validasi teks
            $rowErrors = [];
            if (trim(strip_tags($questionText)) === '') {
                $rowErrors[] = 'Soal tidak boleh kosong.';
            }

            if ($questionType === 'multiple_choice') {
                if (
                    trim(strip_tags($answerA)) === '' ||
                    trim(strip_tags($answerB)) === '' ||
                    trim(strip_tags($answerC)) === '' ||
                    trim(strip_tags($answerD)) === '' ||
                    trim(strip_tags($answerE)) === ''
                ) {
                    $rowErrors[] = 'Semua jawaban A-E harus diisi untuk soal pilihan ganda.';
                }

                if ($weighted) {
                    $weightError = ScoringService::validateOptionWeights(array_values($optionScores));

                    if ($weightError !== null) {
                        $rowErrors[] = 'Kolom Skor A-E: ' . $weightError;
                    } else {
                        $correctAnswer = array_search(max($optionScores), $optionScores, false);
                    }
                } elseif (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'])) {
                    $rowErrors[] = "Kunci jawaban '{$correctAnswer}' tidak valid.";
                }
            }

            if (!empty($rowErrors)) {
                $errors[] = "Baris {$lineNo}: " . implode(' ', $rowErrors);
                $skipped++;
                continue;
            }

            if ($maxQ > 0 && ($currentQ + $imported) >= $maxQ) {
                $errors[] = "Baris {$lineNo}: Batas maksimal soal ({$maxQ}) tercapai.";
                $skipped++;
                continue;
            }

            // Ekstrak gambar jika ada di baris ini
            $imagePath = null;
            if (isset($questionImagesByRow[$rowNum])) {
                $imagePath = $this->extractAndStoreImage($questionImagesByRow[$rowNum], $lineNo, $errors, 'questions');
            }

            $discussionImagePath = null;
            if (isset($discussionImagesByRow[$rowNum])) {
                $discussionImagePath = $this->extractAndStoreImage($discussionImagesByRow[$rowNum], $lineNo, $errors, 'discussion-images');
            }

            $orderNo = $startNo + $imported;
            DB::transaction(function () use ($subtest, $questionText, $answerA, $answerB, $answerC, $answerD, $answerE, $discussion, $discussionImagePath, $correctAnswer, $questionType, $imagePath, $orderNo, $optionScores) {
                $question = Question::create([
                    'subtest_id'       => $subtest->id,
                    'question_type'    => $questionType,
                    'question_text'    => RichTextSanitizer::sanitize($questionText),
                    'question_image'   => $imagePath,
                    'discussion'       => RichTextSanitizer::sanitize($discussion),
                    'discussion_image' => $discussionImagePath,
                    'correct_answer'   => $questionType === 'essay' ? null : $correctAnswer,
                    'order_no'         => $orderNo,
                    'is_active'        => true,
                ]);

                if ($questionType === 'essay') {
                    return;
                }

                foreach (['A' => $answerA, 'B' => $answerB, 'C' => $answerC, 'D' => $answerD, 'E' => $answerE] as $key => $text) {
                    $isCorrect = $key === $correctAnswer;

                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_key'  => $key,
                        'option_text' => RichTextSanitizer::sanitize($text),
                        // Bobot per opsi kalau diisi, selain itu kredit
                        // benar/salah biasa. Kolom ini sebelumnya tidak pernah
                        // ditulis, sehingga setiap opsi hasil impor bernilai 0
                        // dan tidak satu pun ditandai benar.
                        'score'       => $optionScores[$key] !== null
                            ? (float) $optionScores[$key]
                            : ($isCorrect ? 1 : 0),
                        'is_correct'  => $isCorrect,
                    ]);
                }
            });

            $imported++;
        }

        return [$imported, $skipped, $errors];
    }

    // -------------------------------------------------------------------------
    // Ekstrak satu gambar dari Drawing object → simpan ke storage/public
    // Mengembalikan relative path (untuk disimpan ke DB) atau null jika gagal
    // -------------------------------------------------------------------------
    private function extractAndStoreImage(Drawing|MemoryDrawing $drawing, int $lineNo, array &$errors, string $folder = 'questions'): ?string
    {
        try {
            if ($drawing instanceof MemoryDrawing) {
                $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $ext     = $mimeMap[$drawing->getMimeType()] ?? null;

                if (!$ext) {
                    $errors[] = "Baris {$lineNo}: Format gambar tidak didukung.";
                    return null;
                }

                ob_start();
                $renderFunc = $drawing->getRenderingFunction();
                $renderFunc($drawing->getImageResource());
                $content = ob_get_clean();
            } else {
                $path = $drawing->getPath(); // zip://...xlsx#xl/media/imageN.jpg
                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                if (!in_array($ext, self::ALLOWED_IMAGE_EXTS)) {
                    $errors[] = "Baris {$lineNo}: Format gambar '{$ext}' tidak didukung.";
                    return null;
                }

                $content = file_get_contents($path);
            }

            if (empty($content)) {
                $errors[] = "Baris {$lineNo}: Gambar kosong, dilewati.";
                return null;
            }

            $storagePath = $folder . '/' . Str::ulid() . '.' . $ext;
            Storage::disk('public')->put($storagePath, $content);

            return $storagePath;
        } catch (\Throwable $e) {
            $errors[] = "Baris {$lineNo}: Gagal memproses gambar ({$e->getMessage()}).";
            return null;
        }
    }

    private function cellToHtml(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();

        if (! $value instanceof RichText) {
            return nl2br(e(trim((string) $cell->getFormattedValue())), false);
        }

        $html = '';
        foreach ($value->getRichTextElements() as $element) {
            $text = nl2br(e($element->getText()), false);

            if ($element instanceof Run) {
                $font = $element->getFont();
                if ($font?->getBold()) {
                    $text = "<strong>{$text}</strong>";
                }
                if ($font?->getItalic()) {
                    $text = "<em>{$text}</em>";
                }
                if ($font?->getUnderline() && $font->getUnderline() !== 'none') {
                    $text = "<u>{$text}</u>";
                }
                if ($font?->getSuperscript()) {
                    $text = "<sup>{$text}</sup>";
                }
                if ($font?->getSubscript()) {
                    $text = "<sub>{$text}</sub>";
                }
            }

            $html .= $text;
        }

        return RichTextSanitizer::sanitize($html) ?? '';
    }

    // -------------------------------------------------------------------------
    // Download template Excel (format baru dengan kolom Gambar)
    // -------------------------------------------------------------------------
    /**
     * Template Excel, satu per skema penilaian.
     *
     * Sebelumnya hanya ada satu berkas berisi keempat belas kolom sekaligus,
     * dengan lima kolom bobot yang harus dibiarkan kosong oleh subtes
     * benar/salah dan satu kolom Kunci Jawaban yang harus dibiarkan kosong oleh
     * subtes berbobot. Dua aturan yang saling bertolak belakang dalam satu
     * berkas berarti setengah isinya selalu salah bagi siapa pun yang memakainya.
     *
     * Kolom Skor ada di ujung (J-N) sehingga bisa dihilangkan tanpa menggeser
     * apa pun. Kolom Kunci Jawaban ada di tengah (H), jadi pada template
     * berbobot kolomnya tetap ada - importir membaca berdasarkan posisi - hanya
     * ditandai sebagai diabaikan.
     */
    public function excelTemplate(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $weighted = $request->query('scheme') === ScoringService::SCHEME_OPTION_WEIGHT;

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle($weighted ? 'Template Bobot Opsi (TKP)' : 'Template Pilihan Ganda');

        $headers = [
            'Gambar', 'Soal', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E',
            $weighted ? 'Kunci Jawaban (diabaikan)' : 'Kunci Jawaban',
            'Pembahasan',
            'Gambar Pembahasan',
        ];

        if ($weighted) {
            array_push($headers, 'Skor A', 'Skor B', 'Skor C', 'Skor D', 'Skor E');
        }

        $sheet->fromArray($headers, null, 'A1');

        $lastColumn = $weighted ? 'O' : 'J';

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF004AAB']],
        ]);

        if ($weighted) {
            // Lima kolom bobot dibedakan warnanya karena di sinilah nilainya
            // ditentukan, dan kolom kunci jawaban diredupkan karena tidak dibaca.
            $sheet->getStyle('K1:O1')->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC2410C']],
            ]);
            $sheet->getStyle('H1')->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF94A3B8']],
            ]);
        }

        if ($weighted) {
            $sheet->fromArray([
                '',
                'Atasan meminta laporan tambahan menjelang jam pulang. Sikap Anda?',
                'Menolak karena sudah waktunya pulang',
                'Menunda ke esok hari tanpa memberi tahu atasan',
                'Mengerjakan seadanya lalu segera pulang',
                'Menyelesaikan tugas tersebut sebaik mungkin',
                'Meminta rekan lain yang mengerjakan',
                '',
                'Bobot mengukur profesionalisme dan tanggung jawab.',
                '',
                1, 2, 3, 5, 4,
            ], null, 'A2');

            $notes = [
                'Petunjuk Pengisian - Subtes Bobot Opsi (TKP):',
                '- Opsi A s/d E WAJIB diisi semua (5 pilihan jawaban) untuk setiap baris soal.',
                '- Skor A s/d E wajib diisi angka 1 sampai 5, dan setiap angka hanya boleh dipakai satu kali per baris soal.',
                '- Harus ada satu opsi bernilai 5 (respons paling ideal) dan satu opsi bernilai 1 (paling tidak sesuai). Tidak ada opsi bernilai 0.',
                '- Kolom Kunci Jawaban diabaikan: jawaban "benar" otomatis ditentukan dari opsi berbobot 5.',
                '- Semua opsi A-E wajib diisi; tidak ada soal esai pada skema ini.',
                '- Kolom Gambar: embed gambar langsung ke cell (Insert -> Pictures -> Place in Cell).',
                '- Kolom Gambar Pembahasan: embed gambar pembahasan langsung ke cell (opsional).',
                '- Baris pertama adalah header, pengisian data dimulai dari baris 2.',
                '- Format gambar yang didukung: jpg, jpeg, png, webp.',
            ];
        } else {
            $sheet->fromArray([
                '',
                'Berapakah nilai dari 2 + 2?',
                'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh',
                'B',
                'Operasi penjumlahan dasar: 2 + 2 = 4',
                '',
            ], null, 'A2');

            $notes = [
                'Petunjuk Pengisian - Subtes Pilihan Ganda (A-E):',
                '- Soal Pilihan Ganda: Opsi A s/d E WAJIB diisi lengkap (5 pilihan jawaban, tidak boleh dikosongkan).',
                '- Kolom Kunci Jawaban wajib diisi satu huruf: A, B, C, D, atau E sesuai opsi yang benar.',
                '- Soal Esai: kosongkan kolom Kunci Jawaban dan kosongkan kolom Opsi A-E.',
                '- Kolom Gambar: embed gambar langsung ke cell (Insert -> Pictures -> Place in Cell).',
                '- Kolom Gambar Pembahasan: embed gambar pembahasan langsung ke cell (opsional).',
                '- Baris pertama adalah header, pengisian data dimulai dari baris 2.',
                '- Format gambar yang didukung: jpg, jpeg, png, webp.',
            ];
        }

        $noteStart = 4;
        foreach ($notes as $index => $note) {
            $sheet->setCellValue('A' . ($noteStart + $index), $note);
        }
        $noteEnd = $noteStart + count($notes) - 1;
        $sheet->getStyle("A{$noteStart}:A{$noteEnd}")
            ->getFont()->setItalic(true)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF666666'));

        foreach (range('A', $lastColumn) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('B')->setWidth(50); // kolom Soal lebih lebar
        $sheet->getRowDimension(2)->setRowHeight(30);

        $writer = new Xlsx($spreadsheet);

        // Satu pola untuk semua berkas yang dihasilkan aplikasi ini:
        // <jenis>-<rincian>-<tanggal>.xlsx, sama seperti berkas export
        // (laporan-subtes-2026-08-30.xlsx dan seterusnya).
        $filename = sprintf(
            'template-soal-%s-%s.xlsx',
            $weighted ? 'bobot-opsi-tkp' : 'pilihan-ganda',
            now()->format('Y-m-d'),
        );

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
