<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BulkImportQuestionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Subtest $subtest;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->subtest = Subtest::create([
            'name' => 'Subtes Tes Impor',
            'category' => 'TPS',
            'max_questions' => 10,
        ]);
    }

    public function test_excel_template_includes_discussion_image_column_for_both_schemes(): void
    {
        // 1. Skema Benar / Salah
        $response = $this->actingAs($this->admin)->get('/api/admin/questions/bulk-import/excel-template?scheme=right_wrong');
        $response->assertOk();

        $tmpFile = tempnam(sys_get_temp_dir(), 'tpl_rw_') . '.xlsx';
        file_put_contents($tmpFile, $response->streamedContent());
        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Gambar', $sheet->getCell('A1')->getValue());
        $this->assertSame('Soal', $sheet->getCell('B1')->getValue());
        $this->assertSame('Pembahasan', $sheet->getCell('I1')->getValue());
        $this->assertSame('Gambar Pembahasan', $sheet->getCell('J1')->getValue());
        @unlink($tmpFile);

        // 2. Skema Bobot Opsi (TKP)
        $responseWeighted = $this->actingAs($this->admin)->get('/api/admin/questions/bulk-import/excel-template?scheme=option_weight');
        $responseWeighted->assertOk();

        $tmpFileWeighted = tempnam(sys_get_temp_dir(), 'tpl_ow_') . '.xlsx';
        file_put_contents($tmpFileWeighted, $responseWeighted->streamedContent());
        $spreadsheetWeighted = IOFactory::load($tmpFileWeighted);
        $sheetWeighted = $spreadsheetWeighted->getActiveSheet();

        $this->assertSame('Gambar Pembahasan', $sheetWeighted->getCell('J1')->getValue());
        $this->assertSame('Skor A', $sheetWeighted->getCell('K1')->getValue());
        $this->assertSame('Skor B', $sheetWeighted->getCell('L1')->getValue());
        $this->assertSame('Skor C', $sheetWeighted->getCell('M1')->getValue());
        $this->assertSame('Skor D', $sheetWeighted->getCell('N1')->getValue());
        $this->assertSame('Skor E', $sheetWeighted->getCell('O1')->getValue());
        @unlink($tmpFileWeighted);
    }

    public function test_bulk_import_extracts_both_question_image_and_discussion_image(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Gambar', 'Soal', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E',
            'Kunci Jawaban', 'Pembahasan', 'Gambar Pembahasan',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $row2 = [
            '', // Gambar Soal (drawing di A2)
            'Berapakah 5 x 5?',
            '10', '20', '25', '30', '35',
            'C',
            'Penjelasan perkalian 5 x 5 = 25.',
            '', // Gambar Pembahasan (drawing di J2)
        ];
        $sheet->fromArray($row2, null, 'A2');

        // Tambahkan drawing di A2 (Gambar Soal)
        $qGd = imagecreatetruecolor(20, 20);
        $qDrawing = new MemoryDrawing();
        $qDrawing->setName('Question Image');
        $qDrawing->setImageResource($qGd);
        $qDrawing->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
        $qDrawing->setMimeType(MemoryDrawing::MIMETYPE_JPEG);
        $qDrawing->setCoordinates('A2');
        $qDrawing->setWorksheet($sheet);

        // Tambahkan drawing di J2 (Gambar Pembahasan)
        $dGd = imagecreatetruecolor(30, 30);
        $dDrawing = new MemoryDrawing();
        $dDrawing->setName('Discussion Image');
        $dDrawing->setImageResource($dGd);
        $dDrawing->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
        $dDrawing->setMimeType(MemoryDrawing::MIMETYPE_JPEG);
        $dDrawing->setCoordinates('J2');
        $dDrawing->setWorksheet($sheet);

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_test_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        $uploadedFile = new UploadedFile(
            $tmpPath,
            'soal_dengan_gambar_pembahasan.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $response = $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions/bulk-import",
            ['file' => $uploadedFile],
        );

        $response->assertCreated();
        $this->assertSame(1, $response->json('imported'));

        $question = Question::query()->where('subtest_id', $this->subtest->id)->firstOrFail();
        $this->assertNotNull($question->question_image);
        $this->assertStringStartsWith('questions/', $question->question_image);
        Storage::disk('public')->assertExists($question->question_image);

        $this->assertNotNull($question->discussion_image);
        $this->assertStringStartsWith('discussion-images/', $question->discussion_image);
        Storage::disk('public')->assertExists($question->discussion_image);

        $this->assertNotNull($question->discussion_image_url);

        @unlink($tmpPath);
    }

    public function test_bulk_import_backward_compatible_with_old_template_without_discussion_image(): void
    {
        $tkpSubtest = Subtest::create([
            'name' => 'TKP Lama',
            'category' => 'TKP',
            'scoring_scheme' => 'option_weight',
            'max_questions' => 10,
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header format lama (14 kolom: J-N adalah Skor A-E)
        $headers = [
            'Gambar', 'Soal', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E',
            'Kunci Jawaban (diabaikan)', 'Pembahasan',
            'Skor A', 'Skor B', 'Skor C', 'Skor D', 'Skor E',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $row2 = [
            '',
            'Soal format lama',
            'Opsi 1', 'Opsi 2', 'Opsi 3', 'Opsi 4', 'Opsi 5',
            '',
            'Pembahasan lama',
            1, 2, 3, 4, 5,
        ];
        $sheet->fromArray($row2, null, 'A2');

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_old_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        $uploadedFile = new UploadedFile(
            $tmpPath,
            'soal_format_lama.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $response = $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$tkpSubtest->id}/questions/bulk-import",
            ['file' => $uploadedFile],
        );

        $response->assertCreated();
        $this->assertSame(1, $response->json('imported'));

        $question = Question::query()->where('subtest_id', $tkpSubtest->id)->firstOrFail();
        $this->assertNull($question->discussion_image);
        $this->assertSame('E', $question->correct_answer); // Opsi E bernilai 5
        $this->assertCount(5, $question->options);

        @unlink($tmpPath);
    }

    public function test_bulk_import_new_template_option_weight_with_discussion_image_and_scores(): void
    {
        $tkpSubtest = Subtest::create([
            'name' => 'TKP Baru',
            'category' => 'TKP',
            'scoring_scheme' => 'option_weight',
            'max_questions' => 10,
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header format baru (15 kolom: J adalah Gambar Pembahasan, K-O adalah Skor A-E)
        $headers = [
            'Gambar', 'Soal', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E',
            'Kunci Jawaban (diabaikan)', 'Pembahasan', 'Gambar Pembahasan',
            'Skor A', 'Skor B', 'Skor C', 'Skor D', 'Skor E',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $row2 = [
            '',
            'Soal TKP Baru',
            'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E',
            '',
            'Pembahasan TKP Baru',
            '', // Kolom J: Gambar Pembahasan
            5, 4, 3, 2, 1, // Kolom K-O: Opsi A bernilai 5
        ];
        $sheet->fromArray($row2, null, 'A2');

        // Tambahkan drawing pembahasan di J2
        $dGd = imagecreatetruecolor(25, 25);
        $dDrawing = new MemoryDrawing();
        $dDrawing->setName('TKP Discussion Image');
        $dDrawing->setImageResource($dGd);
        $dDrawing->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
        $dDrawing->setMimeType(MemoryDrawing::MIMETYPE_JPEG);
        $dDrawing->setCoordinates('J2');
        $dDrawing->setWorksheet($sheet);

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_new_tkp_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        $uploadedFile = new UploadedFile(
            $tmpPath,
            'soal_tkp_baru.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $response = $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$tkpSubtest->id}/questions/bulk-import",
            ['file' => $uploadedFile],
        );

        $response->assertCreated();
        $this->assertSame(1, $response->json('imported'));

        $question = Question::query()->where('subtest_id', $tkpSubtest->id)->firstOrFail();
        $this->assertNotNull($question->discussion_image);
        $this->assertStringStartsWith('discussion-images/', $question->discussion_image);
        Storage::disk('public')->assertExists($question->discussion_image);

        $this->assertSame('A', $question->correct_answer); // Opsi A berbobot 5
        $optionA = $question->options()->where('option_key', 'A')->first();
        $this->assertEquals(5.0, $optionA->score);
        $this->assertTrue($optionA->is_correct);

        @unlink($tmpPath);
    }
}
