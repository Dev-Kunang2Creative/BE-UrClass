<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jenjang pendidikan peserta, termasuk jurusan untuk yang sudah lulus kuliah.
 *
 * Jalur CPNS melayani dua audiens: siswa SMA yang membidik sekolah kedinasan,
 * dan wisudawan yang melamar CPNS umum. Yang kedua tidak punya "kelas" - yang
 * relevan justru jenjang terakhir beserta jurusannya.
 */
class JenjangPendidikanTest extends TestCase
{
    use RefreshDatabase;

    private function simpan(array $ubah = []): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create(['kategori' => 'cpns', 'role' => 'user']);

        return $this->actingAs($user)->putJson('/api/profile/update', array_merge([
            'name' => 'Naniek Matanari',
            'phone_number' => '081234567890',
            'birth_date' => '2000-01-01',
            'gender' => 'P',
            'school_origin' => 'SMAN 1 Bekasi',
            'grade_level' => 'S1',
            'education_major' => 'Akuntansi',
            'cpns_target_type' => 'umum',
            'target_instansi_1' => 'Kementerian Keuangan',
        ], $ubah));
    }

    public function test_jenjang_pendidikan_tinggi_wajib_menyertakan_jurusan(): void
    {
        foreach (['D3', 'D4', 'S1', 'S2'] as $jenjang) {
            $this->simpan(['grade_level' => $jenjang, 'education_major' => null])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('education_major');
        }
    }

    public function test_wisudawan_tersimpan_lengkap_dengan_jurusannya(): void
    {
        $user = User::factory()->create(['kategori' => 'cpns', 'role' => 'user']);

        $this->actingAs($user)->putJson('/api/profile/update', [
            'name' => 'Naniek Matanari',
            'phone_number' => '081234567890',
            'birth_date' => '2000-01-01',
            'gender' => 'P',
            'school_origin' => 'Universitas Indonesia',
            'grade_level' => 'S1',
            'education_major' => 'Akuntansi',
            'cpns_target_type' => 'umum',
            'target_instansi_1' => 'Kementerian Keuangan',
        ])->assertOk();

        $segar = $user->fresh();
        $this->assertSame('S1', $segar->grade_level);
        $this->assertSame('Akuntansi', $segar->education_major);
    }

    public function test_siswa_sma_aktif_tidak_diminta_jurusan(): void
    {
        $this->simpan([
            'grade_level' => 'SMA/SMK Kelas 12',
            'education_major' => null,
            'cpns_target_type' => 'kedinasan',
            'target_university_1' => 'Politeknik Keuangan Negara STAN',
            'target_instansi_1' => null,
        ])->assertOk();
    }

    public function test_pindah_ke_jenjang_sma_mengosongkan_jurusan_lama(): void
    {
        $user = User::factory()->create([
            'kategori' => 'cpns', 'role' => 'user',
            'grade_level' => 'S1', 'education_major' => 'Akuntansi',
        ]);

        $this->actingAs($user)->putJson('/api/profile/update', [
            'name' => 'Naniek Matanari',
            'phone_number' => '081234567890',
            'birth_date' => '2000-01-01',
            'gender' => 'P',
            'school_origin' => 'SMAN 1 Bekasi',
            'grade_level' => 'SMA/SMK Kelas 12',
            'cpns_target_type' => 'kedinasan',
            'target_university_1' => 'Politeknik Keuangan Negara STAN',
        ])->assertOk();

        // Kalau tidak dikosongkan, "S1 Akuntansi" tertinggal pada siswa SMA dan
        // tidak akan pernah terlihat lagi untuk diperbaiki.
        $this->assertNull($user->fresh()->education_major);
    }

    public function test_lulusan_sma_diterima_sebagai_jenjang_tanpa_kelas(): void
    {
        $this->simpan([
            'grade_level' => 'Lulusan SMA/SMK',
            'education_major' => null,
        ])->assertOk();
    }
}
