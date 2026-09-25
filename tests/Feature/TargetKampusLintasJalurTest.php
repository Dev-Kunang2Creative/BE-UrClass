<?php

namespace Tests\Feature;

use App\Models\PerguruanTinggi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sekolah kedinasan tidak boleh masuk ke jalur UTBK, dan sebaliknya.
 *
 * Penyaringan `jenis` di endpoint daftar hanya membentuk isi dropdown. Karena
 * kolom referensi wajib menerima ketikan manual, penyaringan itu tidak pernah
 * bisa jadi penjagaan - yang menjaga adalah pemeriksaan saat menyimpan.
 */
class TargetKampusLintasJalurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PerguruanTinggi::create(['kode_ptn' => 'PTN-UI', 'nama' => 'Universitas Indonesia', 'jenis' => 'ptn']);
        PerguruanTinggi::create(['kode_ptn' => 'KD-IPDN', 'nama' => 'Institut Pemerintahan Dalam Negeri', 'jenis' => 'kedinasan']);
    }

    private function profil(array $ubah = []): array
    {
        return array_merge([
            'name' => 'Siswa',
            'phone_number' => '081234567890',
            'birth_date' => '2006-01-01',
            'gender' => 'L',
            'school_origin' => 'SMAN 1',
            'grade_level' => '12',
            'target_university_1' => 'Universitas Indonesia',
            'target_major_1' => 'Ilmu Komputer',
        ], $ubah);
    }

    public function test_peserta_utbk_tidak_bisa_menyimpan_sekolah_kedinasan(): void
    {
        $user = User::factory()->create(['kategori' => 'utbk', 'role' => 'user']);

        $this->actingAs($user)->putJson('/api/profile/update', $this->profil([
            'target_university_1' => 'Institut Pemerintahan Dalam Negeri',
        ]))->assertUnprocessable()->assertJsonValidationErrors('target_university_1');

        $this->assertNull($user->fresh()->target_university_1);
    }

    public function test_pilihan_kedua_ikut_dijaga_dan_besar_kecil_huruf_tidak_menolong(): void
    {
        $user = User::factory()->create(['kategori' => 'utbk', 'role' => 'user']);

        $this->actingAs($user)->putJson('/api/profile/update', $this->profil([
            'target_university_2' => 'institut pemerintahan dalam negeri',
        ]))->assertUnprocessable()->assertJsonValidationErrors('target_university_2');
    }

    public function test_pelamar_kedinasan_tidak_bisa_menyimpan_ptn(): void
    {
        $user = User::factory()->create(['kategori' => 'cpns', 'role' => 'user']);

        $this->actingAs($user)->putJson('/api/profile/update', $this->profil([
            'cpns_target_type' => 'kedinasan',
            'target_university_1' => 'Universitas Indonesia',
        ]))->assertUnprocessable()->assertJsonValidationErrors('target_university_1');
    }

    public function test_kampus_di_jalur_yang_benar_tetap_tersimpan(): void
    {
        $user = User::factory()->create(['kategori' => 'utbk', 'role' => 'user']);

        $this->actingAs($user)->putJson('/api/profile/update', $this->profil())->assertOk();
        $this->assertSame('Universitas Indonesia', $user->fresh()->target_university_1);
    }

    public function test_kampus_yang_belum_terdaftar_tetap_boleh_diketik_manual(): void
    {
        // Tabelnya hanya memuat kampus negeri. Menolak semua yang tak dikenal
        // akan menghalangi peserta yang kampus tujuannya memang belum ada.
        $user = User::factory()->create(['kategori' => 'utbk', 'role' => 'user']);

        $this->actingAs($user)->putJson('/api/profile/update', $this->profil([
            'target_university_1' => 'Universitas Swasta Yang Belum Terdaftar',
        ]))->assertOk();
    }

    public function test_pindah_jalur_mengosongkan_target_yang_bertentangan(): void
    {
        $user = User::factory()->create([
            'kategori' => 'cpns',
            'role' => 'user',
            'cpns_target_type' => 'kedinasan',
            'target_university_1' => 'Institut Pemerintahan Dalam Negeri',
            'target_major_1' => 'Politik Pemerintahan',
        ]);

        $this->actingAs($user)->putJson('/api/profile/kategori', ['kategori' => 'utbk'])->assertOk();

        $segar = $user->fresh();
        $this->assertNull($segar->target_university_1);
        $this->assertNull($segar->target_major_1);

        // Sub-jalurnya justru harus bertahan. Mengosongkannya pernah dilakukan
        // dan menimbulkan bug: kembali ke CPNS, form jatuh ke "kedinasan" dan
        // menyembunyikan instansi yang sudah diisi peserta.
        $this->assertSame('kedinasan', $segar->cpns_target_type);
    }

    public function test_pindah_jalur_pulang_pergi_mempertahankan_sub_jalur_dan_instansi(): void
    {
        // Sub-jalur yang dikosongkan saat pindah ke UTBK membuat form jatuh ke
        // "kedinasan" begitu peserta kembali - dan pilihan itu menyembunyikan
        // seluruh bagian instansi, sehingga instansi yang sudah diisi tampak
        // lenyap padahal barisnya masih utuh.
        $user = User::factory()->create([
            'kategori' => 'cpns',
            'role' => 'user',
            'cpns_target_type' => 'umum',
            'target_instansi_1' => 'Kementerian Keuangan',
            'target_formasi_1' => 'Analis Anggaran',
        ]);

        $this->actingAs($user)->putJson('/api/profile/kategori', ['kategori' => 'utbk'])->assertOk();
        $this->actingAs($user)->putJson('/api/profile/kategori', ['kategori' => 'cpns'])->assertOk();

        $segar = $user->fresh();
        $this->assertSame('umum', $segar->cpns_target_type);
        $this->assertSame('Kementerian Keuangan', $segar->target_instansi_1);
        $this->assertSame('Analis Anggaran', $segar->target_formasi_1);
    }

    public function test_pindah_jalur_mempertahankan_target_yang_masih_sah(): void
    {
        // Kampus yang tidak dikenal tabel bukan "salah jalur", jadi tidak ada
        // alasan menghapusnya dan memaksa peserta mengetik ulang.
        $user = User::factory()->create([
            'kategori' => 'cpns',
            'role' => 'user',
            'target_university_1' => 'Sekolah Tinggi Yang Belum Terdaftar',
            'target_major_1' => 'Akuntansi',
        ]);

        $this->actingAs($user)->putJson('/api/profile/kategori', ['kategori' => 'utbk'])->assertOk();

        $segar = $user->fresh();
        $this->assertSame('Sekolah Tinggi Yang Belum Terdaftar', $segar->target_university_1);
        $this->assertSame('Akuntansi', $segar->target_major_1);
    }

    public function test_admin_tidak_terkena_pemeriksaan_jalur(): void
    {
        // Admin tidak punya jalur ujian, jadi tidak ada jalur yang bisa dilanggar.
        $admin = User::factory()->create(['kategori' => 'utbk', 'role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/profile/update', [
            'name' => 'Admin',
            'target_university_1' => 'Institut Pemerintahan Dalam Negeri',
        ])->assertOk();
    }
}
