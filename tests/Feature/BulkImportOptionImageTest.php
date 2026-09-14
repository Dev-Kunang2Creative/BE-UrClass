<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Impor Excel membawa gambar untuk opsi jawaban A-E, bukan hanya soal dan
 * pembahasan.
 *
 * Gambarnya ditempel di sel opsinya sendiri (kolom C-G), sehingga satu sel bisa
 * memuat teks sekaligus gambar. Yang dibaca hanya gambar mengambang
 * ("Place over Cells"); gambar "Place in Cell" milik Excel 365 disimpan sebagai
 * rich value di xl/richData dan tidak terbaca PhpSpreadsheet 3.x.
 */
class BulkImportOptionImageTest extends TestCase
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
            'name' => 'Impor Gambar Opsi',
            'category' => 'TPS',
            'max_questions' => 10,
        ]);
    }

    private function gambarDi(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $koordinat): void
    {
        $drawing = new MemoryDrawing();
        $drawing->setName('Gambar '.$koordinat);
        $drawing->setImageResource(imagecreatetruecolor(20, 20));
        $drawing->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
        $drawing->setMimeType(MemoryDrawing::MIMETYPE_JPEG);
        $drawing->setCoordinates($koordinat);
        $drawing->setWorksheet($sheet);
    }

    private function unggah(Spreadsheet $spreadsheet): \Illuminate\Testing\TestResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'impor_opsi_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);

        return $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions/bulk-import",
            ['file' => new UploadedFile($tmp, 'soal.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)],
        );
    }

    private function lembar(array $baris2): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            'Gambar', 'Soal', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E',
            'Kunci Jawaban', 'Pembahasan', 'Gambar Pembahasan',
        ], null, 'A1');
        $sheet->fromArray($baris2, null, 'A2');

        return $spreadsheet;
    }

    public function test_gambar_di_sel_opsi_tersimpan_pada_opsi_yang_bersangkutan(): void
    {
        $spreadsheet = $this->lembar([
            '', 'Bangun mana yang simetris?',
            'Lingkaran', 'Trapesium', 'Jajar genjang', 'Layang-layang', 'Belah ketupat',
            'A', 'Lingkaran simetris dari segala arah.', '',
        ]);
        // C2 = Opsi A, E2 = Opsi C.
        $this->gambarDi($spreadsheet->getActiveSheet(), 'C2');
        $this->gambarDi($spreadsheet->getActiveSheet(), 'E2');

        $this->unggah($spreadsheet)->assertCreated();

        $opsi = Question::query()->firstOrFail()->options->keyBy('option_key');

        $this->assertStringStartsWith('option-images/', $opsi['A']->image);
        Storage::disk('public')->assertExists($opsi['A']->image);
        // Teksnya tetap ada: gambar melengkapi, bukan menggantikan.
        $this->assertSame('Lingkaran', strip_tags($opsi['A']->option_text));

        $this->assertStringStartsWith('option-images/', $opsi['C']->image);
        $this->assertNotSame($opsi['A']->image, $opsi['C']->image);

        // Opsi yang tidak ditempeli gambar tetap tanpa gambar.
        $this->assertNull($opsi['B']->image);
        $this->assertNull($opsi['D']->image);
        $this->assertNull($opsi['E']->image);
    }

    public function test_opsi_bergambar_tanpa_teks_diterima(): void
    {
        $spreadsheet = $this->lembar([
            '', 'Manakah grafik fungsi naik?',
            '', '', '', '', '',
            'B', 'Grafik kedua naik dari kiri ke kanan.', '',
        ]);
        foreach (['C2', 'D2', 'E2', 'F2', 'G2'] as $sel) {
            $this->gambarDi($spreadsheet->getActiveSheet(), $sel);
        }

        $response = $this->unggah($spreadsheet)->assertCreated();
        $this->assertSame(1, $response->json('imported'));

        $opsi = Question::query()->firstOrFail()->options->keyBy('option_key');
        foreach (['A', 'B', 'C', 'D', 'E'] as $kunci) {
            $this->assertNotNull($opsi[$kunci]->image, "Opsi {$kunci} kehilangan gambarnya");
        }
    }

    public function test_opsi_tanpa_teks_dan_tanpa_gambar_tetap_ditolak(): void
    {
        // Yang dilonggarkan hanya "teks wajib", bukan "opsi boleh kosong".
        $spreadsheet = $this->lembar([
            '', 'Manakah grafik fungsi naik?',
            '', '', '', '', '',
            'B', 'Pembahasan.', '',
        ]);
        $this->gambarDi($spreadsheet->getActiveSheet(), 'C2');

        $response = $this->unggah($spreadsheet);

        $this->assertSame(0, $response->json('imported'));
        $this->assertSame(1, $response->json('skipped'));
        $this->assertStringContainsString('B, C, D, E', $response->json('errors.0'));
    }
}
