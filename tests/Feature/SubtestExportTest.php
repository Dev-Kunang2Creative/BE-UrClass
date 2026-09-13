<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Models\SubtestCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SubtestExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $student;
    protected Subtest $subtest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'  => 'admin',
            'email' => 'admin@urclass.test',
        ]);

        $this->student = User::factory()->create([
            'role'     => 'user',
            'email'    => 'siswa@urclass.test',
            'kategori' => 'cpns',
        ]);

        SubtestCategory::create([
            'code'      => 'TIU',
            'name'      => 'TIU',
            'exam_type' => 'cpns',
            'is_active' => true,
        ]);

        $this->subtest = Subtest::create([
            'name'           => 'TIU Latihan 1',
            'exam_type'      => 'cpns',
            'category'       => 'TIU',
            'max_questions'  => 50,
            'scoring_scheme' => 'right_wrong',
            'score_correct'  => 5,
            'score_wrong'    => 0,
            'score_empty'    => 0,
        ]);

        // Buat 2 soal dengan opsi A-E
        for ($i = 1; $i <= 2; $i++) {
            $question = Question::create([
                'subtest_id'     => $this->subtest->id,
                'question_text'  => "Pertanyaan nomor {$i} tentang analogi kata.",
                'discussion'     => "Pembahasan rinci nomor {$i}.",
                'correct_answer' => 'B',
                'order_no'       => $i,
                'is_active'      => true,
            ]);

            foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key'  => $key,
                    'option_text' => "Pilihan {$key} untuk soal {$i}",
                    'score'       => $key === 'B' ? 1.0 : 0.0,
                ]);
            }
        }
    }

    public function test_non_admin_cannot_export_subtest(): void
    {
        $this->actingAs($this->student)
            ->get("/api/admin/subtests/{$this->subtest->id}/export-pdf")
            ->assertForbidden();

        $this->actingAs($this->student)
            ->get("/api/admin/subtests/{$this->subtest->id}/export-excel")
            ->assertForbidden();
    }

    public function test_admin_can_export_subtest_pdf_without_answers(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/api/admin/subtests/{$this->subtest->id}/export-pdf");

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="Naskah_Soal_', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_admin_can_export_subtest_excel_clean_question_sheet(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/api/admin/subtests/{$this->subtest->id}/export-excel");

        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="soal-', $response->headers->get('Content-Disposition') ?? '');

        // Verifikasi isi berkas Excel yang dihasilkan
        $tempFile = tempnam(sys_get_temp_dir(), 'test_excel_');
        file_put_contents($tempFile, $response->streamedContent());

        $spreadsheet = IOFactory::load($tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Header
        $this->assertEquals('No', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Pertanyaan / Soal', $sheet->getCell('B1')->getValue());
        $this->assertEquals('Opsi A', $sheet->getCell('C1')->getValue());
        $this->assertEquals('Opsi B', $sheet->getCell('D1')->getValue());
        $this->assertEquals('Opsi C', $sheet->getCell('E1')->getValue());
        $this->assertEquals('Opsi D', $sheet->getCell('F1')->getValue());
        $this->assertEquals('Opsi E', $sheet->getCell('G1')->getValue());

        // Baris pertama (soal 1)
        $this->assertEquals(1, $sheet->getCell('A2')->getValue());
        $this->assertStringContainsString('Pertanyaan nomor 1', $sheet->getCell('B2')->getValue());
        $this->assertEquals('Pilihan A untuk soal 1', $sheet->getCell('C2')->getValue());
        $this->assertEquals('Pilihan B untuk soal 1', $sheet->getCell('D2')->getValue());

        // Pastikan tidak ada kolom kunci jawaban atau pembahasan di sheet soal ini
        $this->assertNull($sheet->getCell('H1')->getValue());

        unlink($tempFile);
    }
}
