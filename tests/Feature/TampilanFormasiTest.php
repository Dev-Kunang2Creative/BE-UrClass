<?php

namespace Tests\Feature;

use App\Models\Formasi;
use App\Models\Instansi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saklar admin "tampilkan formasi ke peserta".
 */
class TampilanFormasiTest extends TestCase
{
    use RefreshDatabase;

    private User $pelamar;

    protected function setUp(): void
    {
        parent::setUp();

        $instansi = Instansi::create(['kode' => 'KL-KEMENKEU', 'nama' => 'Kementerian Keuangan', 'tingkat' => 'pusat', 'is_active' => true]);
        Formasi::create([
            'instansi_id' => $instansi->id, 'nama' => 'Ahli Pertama - Analis',
            'jenjang' => 'S-1', 'periode' => (int) now()->year, 'is_active' => true,
        ]);

        $this->pelamar = User::factory()->create([
            'kategori' => 'cpns', 'role' => 'user', 'target_formasi_1' => 'Ahli Pertama - Analis',
        ]);
    }

    private function atur(bool $ditampilkan): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->putJson('/api/admin/formasi/tampilan', ['ditampilkan' => $ditampilkan]);
    }

    private function simpanTanpaFormasi(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->pelamar)->putJson('/api/profile/update', [
            'name' => 'Budi Pelamar',
            'phone_number' => '081234567890',
            'birth_date' => '2000-01-01',
            'gender' => 'L',
            'school_origin' => 'Universitas Airlangga',
            'grade_level' => 'S1',
            'education_major' => 'Akuntansi',
            'cpns_target_type' => 'umum',
            'target_instansi_1' => 'Kementerian Keuangan',
        ]);
    }

    public function test_bawaannya_formasi_ditampilkan_dan_wajib(): void
    {
        $this->actingAs($this->pelamar)->getJson('/api/formasi/status')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_open', true);

        $this->simpanTanpaFormasi()->assertJsonValidationErrors('target_formasi_1');
    }

    public function test_saat_disembunyikan_formasi_tidak_tampil_dan_tidak_wajib(): void
    {
        $this->atur(false)->assertOk()->assertJsonPath('data.ditampilkan', false);

        $this->actingAs($this->pelamar)->getJson('/api/formasi/status')
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_open', false);

        $this->simpanTanpaFormasi()->assertOk();

        // Pilihan yang sudah tersimpan tidak ikut terhapus.
        $this->assertSame('Ahli Pertama - Analis', $this->pelamar->fresh()->target_formasi_1);
        $this->assertDatabaseCount('formasi', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Formasi', 'action' => 'visibility']);
    }

    public function test_menyalakan_lagi_mengembalikan_formasi(): void
    {
        $this->atur(false);
        $this->atur(true)->assertOk();

        $this->actingAs($this->pelamar)->getJson('/api/formasi/status')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_open', true);
    }

    public function test_peserta_tidak_bisa_mengubah_saklarnya(): void
    {
        $this->actingAs($this->pelamar)
            ->putJson('/api/admin/formasi/tampilan', ['ditampilkan' => false])
            ->assertForbidden();
    }
}
