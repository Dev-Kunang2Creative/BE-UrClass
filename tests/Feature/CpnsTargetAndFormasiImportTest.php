<?php

namespace Tests\Feature;

use App\Models\Formasi;
use App\Models\Instansi;
use App\Models\PerguruanTinggi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Target jalur CPNS dan jalan masuk rekap formasi.
 *
 * Dua hal yang mudah rusak diam-diam dan sulit terlihat dari antarmuka:
 * pemisahan PTN dari sekolah kedinasan di tabel yang sama, dan keadaan
 * "formasi belum dibuka" yang menentukan apakah kolom formasi diwajibkan.
 */
class CpnsTargetAndFormasiImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $pelamar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@urclass.test',
        ]);

        $this->pelamar = User::factory()->create([
            'role' => 'user',
            'email' => 'pelamar@urclass.test',
            'kategori' => 'cpns',
        ]);

        PerguruanTinggi::create(['kode_ptn' => 'PTN-UI', 'nama' => 'Universitas Indonesia', 'jenis' => 'ptn']);
        PerguruanTinggi::create(['kode_ptn' => 'KD-IPDN', 'nama' => 'Institut Pemerintahan Dalam Negeri', 'jenis' => 'kedinasan']);

        Instansi::create(['kode' => 'PEMKOT-SBY', 'nama' => 'Pemerintah Kota Surabaya', 'tingkat' => 'daerah', 'is_active' => true]);
        Instansi::create(['kode' => 'KL-KEMENKEU', 'nama' => 'Kementerian Keuangan', 'tingkat' => 'pusat', 'is_active' => true]);
    }

    /**
     * Sekolah kedinasan menempati tabel yang sama dengan PTN. Tanpa filter jenis,
     * peserta UTBK akan menemukan IPDN di daftar target kampusnya.
     */
    public function test_daftar_kampus_terpisah_menurut_jenis(): void
    {
        $ptn = $this->actingAs($this->pelamar)->getJson('/api/perguruan-tinggi?jenis=ptn');
        $ptn->assertOk();
        $this->assertSame(1, $ptn->json('total'));
        $this->assertSame('Universitas Indonesia', $ptn->json('data.0.nama'));

        $kedinasan = $this->actingAs($this->pelamar)->getJson('/api/perguruan-tinggi?jenis=kedinasan');
        $kedinasan->assertOk();
        $this->assertSame(1, $kedinasan->json('total'));
        $this->assertSame('Institut Pemerintahan Dalam Negeri', $kedinasan->json('data.0.nama'));

        // IPDN tidak boleh bocor ke daftar PTN.
        $bocor = $this->actingAs($this->pelamar)->getJson('/api/perguruan-tinggi?jenis=ptn&search=Pemerintahan');
        $this->assertSame(0, $bocor->json('total'));
    }

    public function test_status_formasi_tertutup_selama_belum_ada_formasi(): void
    {
        $response = $this->actingAs($this->pelamar)->getJson('/api/formasi/status');

        $response->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.periode', (int) now()->year)
            ->assertJsonPath('data.instansi_total', 2);
    }

    /**
     * Selama rekapnya belum terbit, formasi tidak boleh diwajibkan - kalau
     * diwajibkan, pelamar CPNS umum tidak bisa menyimpan profilnya sama sekali.
     */
    public function test_profil_cpns_umum_bisa_disimpan_tanpa_formasi_saat_masih_tertutup(): void
    {
        $response = $this->actingAs($this->pelamar)->putJson('/api/profile/update', [
            'name' => 'Budi Pelamar',
            'phone_number' => '081234567890',
            'birth_date' => '2000-01-01',
            'gender' => 'L',
            'school_origin' => 'SMAN 1 Surabaya',
            'grade_level' => 'Gap Year',
            'cpns_target_type' => 'umum',
            'target_instansi_1' => 'Pemerintah Kota Surabaya',
        ]);

        $response->assertOk();
        $this->assertSame('umum', $this->pelamar->fresh()->cpns_target_type);
    }

    /** Begitu ada formasi, kolomnya kembali wajib. */
    public function test_formasi_wajib_setelah_rekapnya_terbit(): void
    {
        $instansi = Instansi::where('kode', 'PEMKOT-SBY')->first();
        Formasi::create([
            'instansi_id' => $instansi->id,
            'nama' => 'Ahli Pertama - Perencana',
            'jenjang' => 'S-1',
            'periode' => (int) now()->year,
            'is_active' => true,
        ]);

        $status = $this->actingAs($this->pelamar)->getJson('/api/formasi/status');
        $status->assertJsonPath('data.is_open', true)->assertJsonPath('data.total', 1);

        $this->actingAs($this->pelamar)->putJson('/api/profile/update', [
            'name' => 'Budi Pelamar',
            'phone_number' => '081234567890',
            'birth_date' => '2000-01-01',
            'gender' => 'L',
            'school_origin' => 'SMAN 1 Surabaya',
            'grade_level' => 'Gap Year',
            'cpns_target_type' => 'umum',
            'target_instansi_1' => 'Pemerintah Kota Surabaya',
        ])->assertStatus(422)->assertJsonValidationErrors('target_formasi_1');
    }

    public function test_impor_excel_menambahkan_formasi_dan_bisa_diulang(): void
    {
        $file = $this->excel([
            ['NAMA_INSTANSI', 'KODE_INSTANSI', 'NAMA_FORMASI', 'JENJANG', 'PERIODE'],
            ['Pemerintah Kota Surabaya', '', 'Ahli Pertama - Perencana', 'S-1', 2026],
            // Ejaan berbeda harus tetap cocok: rekap dari sumber berbeda
            // menuliskan nama instansi dengan kapital dan spasi yang tidak sama.
            ['  kementerian   KEUANGAN ', '', 'Ahli Pertama - Auditor', 'S-1', 2026],
        ]);

        $pertama = $this->actingAs($this->admin)
            ->post('/api/admin/formasi/import', ['file' => $file]);

        $pertama->assertStatus(201)
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.error_total', 0);

        $this->assertSame(2, Formasi::count());

        // Diunggah ulang: diperbarui, bukan diduplikasi. Ini yang membuat berkas
        // yang sudah dikoreksi aman dikirim lagi.
        $kedua = $this->actingAs($this->admin)
            ->post('/api/admin/formasi/import', ['file' => $this->excel([
                ['NAMA_INSTANSI', 'KODE_INSTANSI', 'NAMA_FORMASI', 'JENJANG', 'PERIODE'],
                ['Pemerintah Kota Surabaya', '', 'Ahli Pertama - Perencana', 'S-1', 2026],
                ['Kementerian Keuangan', '', 'Ahli Pertama - Auditor', 'S-1', 2026],
            ])]);

        $kedua->assertStatus(201)
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(2, Formasi::count());
    }

    public function test_impor_menolak_instansi_tak_dikenal_kecuali_diminta(): void
    {
        $rows = [
            ['NAMA_INSTANSI', 'KODE_INSTANSI', 'NAMA_FORMASI', 'JENJANG', 'PERIODE'],
            ['Kementerian Antariksa Nusantara', '', 'Ahli Pertama - Astronom', 'S-1', 2026],
        ];

        $tolak = $this->actingAs($this->admin)
            ->post('/api/admin/formasi/import', ['file' => $this->excel($rows)]);

        $tolak->assertStatus(422)
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.instansi_created', 0);
        $this->assertStringContainsString('tidak dikenali', $tolak->json('data.errors.0'));
        $this->assertSame(2, Instansi::count());

        $terima = $this->actingAs($this->admin)->post('/api/admin/formasi/import', [
            'file' => $this->excel($rows),
            'create_missing_instansi' => '1',
        ]);

        $terima->assertStatus(201)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.instansi_created', 1);
        $this->assertSame(3, Instansi::count());
    }

    public function test_impor_melaporkan_baris_bermasalah_per_nomor_baris(): void
    {
        $response = $this->actingAs($this->admin)->post('/api/admin/formasi/import', [
            'file' => $this->excel([
                ['NAMA_INSTANSI', 'KODE_INSTANSI', 'NAMA_FORMASI', 'JENJANG', 'PERIODE'],
                ['Pemerintah Kota Surabaya', '', 'Ahli Pertama - Perencana', 'S-1', 2026],
                ['Pemerintah Kota Surabaya', '', '', 'S-1', 2026],
                ['Pemerintah Kota Surabaya', '', 'Ahli <script>alert(1)</script>', 'S-1', 2026],
                ['Pemerintah Kota Surabaya', '', str_repeat('X', 300), 'S-1', 2026],
            ]),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 3)
            ->assertJsonPath('data.error_total', 3);

        $errors = implode("\n", $response->json('data.errors'));
        $this->assertStringContainsString('Baris 3', $errors);
        $this->assertStringContainsString('Baris 4', $errors);
        $this->assertStringContainsString('Baris 5', $errors);
    }

    /** Periode salah tulis diganti tahun berjalan, bukan disimpan apa adanya. */
    public function test_periode_tidak_masuk_akal_diganti_tahun_berjalan(): void
    {
        $this->actingAs($this->admin)->post('/api/admin/formasi/import', [
            'file' => $this->excel([
                ['NAMA_INSTANSI', 'KODE_INSTANSI', 'NAMA_FORMASI', 'JENJANG', 'PERIODE'],
                ['Pemerintah Kota Surabaya', '', 'Ahli Pertama - Perencana', 'S-1', '2O26'],
                ['Pemerintah Kota Surabaya', '', 'Ahli Pertama - Statistisi', 'S-1', ''],
            ]),
        ])->assertStatus(201);

        $this->assertSame(
            [(int) now()->year, (int) now()->year],
            Formasi::orderBy('nama')->pluck('periode')->map(fn ($p) => (int) $p)->all(),
        );
    }

    public function test_peserta_tidak_boleh_mengimpor_formasi(): void
    {
        $this->actingAs($this->pelamar)
            ->post('/api/admin/formasi/import', ['file' => $this->excel([['NAMA_INSTANSI']])])
            ->assertForbidden();

        $this->actingAs($this->pelamar)
            ->getJson('/api/admin/formasi/import/template')
            ->assertForbidden();
    }

    public function test_template_bisa_diunduh_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/admin/formasi/import/template');

        $response->assertOk();
        $this->assertStringContainsString(
            'template-formasi-cpns-',
            $response->headers->get('content-disposition'),
        );
    }

    /** Berkas xlsx sungguhan, supaya jalur PhpSpreadsheet ikut teruji. */
    private function excel(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'formasi').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'formasi.xlsx', null, null, true);
    }
}
