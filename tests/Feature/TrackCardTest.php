<?php

namespace Tests\Feature;

use App\Models\TrackCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teks kartu pemilihan jalur, dikelola admin.
 *
 * Tabelnya menyimpan perubahan saja; yang tidak diubah jatuh ke teks bawaan di
 * kode. Karena itu kartunya harus tampil utuh bahkan saat tabelnya masih kosong
 * - keadaan yang justru berlaku tepat setelah migrasinya jalan di produksi,
 * tempat seeder tidak pernah dijalankan.
 */
class TrackCardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_kartu_tampil_utuh_walau_tabelnya_masih_kosong(): void
    {
        $this->assertDatabaseCount('track_cards', 0);

        $this->getJson('/api/track-cards')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kategori', 'utbk')
            ->assertJsonPath('data.0.title', 'Tryout UTBK - SNBT')
            ->assertJsonPath('data.1.kategori', 'cpns')
            ->assertJsonPath('data.1.title', 'Tryout Sekolah Kedinasan & CPNS')
            ->assertJsonCount(3, 'data.1.features');
    }

    public function test_admin_mengubah_teks_dan_peserta_melihat_perubahannya(): void
    {
        $this->actingAs($this->admin())->putJson('/api/admin/track-cards/cpns', [
            'title' => 'Tryout Kedinasan dan CPNS 2026',
            'badge' => 'SKD RESMI',
            'cta' => 'Mulai Sekarang',
            'description' => 'Latihan SKD lengkap menjelang seleksi.',
            'features' => ['Simulasi CAT BKN', 'Pembahasan lengkap'],
        ])->assertOk();

        $this->getJson('/api/track-cards')
            ->assertOk()
            ->assertJsonPath('data.1.title', 'Tryout Kedinasan dan CPNS 2026')
            ->assertJsonPath('data.1.badge', 'SKD RESMI')
            ->assertJsonCount(2, 'data.1.features')
            // Jalur yang tidak disentuh tidak ikut berubah.
            ->assertJsonPath('data.0.title', 'Tryout UTBK - SNBT');
    }

    public function test_kolom_yang_dikosongkan_kembali_ke_teks_bawaan(): void
    {
        // Kartu tanpa judul adalah satu-satunya keadaan yang tidak boleh bisa
        // dicapai lewat panel admin - peserta memakainya untuk memilih jalur.
        TrackCard::create(['kategori' => 'utbk', 'title' => 'Diubah dulu']);

        $this->actingAs($this->admin())
            ->putJson('/api/admin/track-cards/utbk', ['title' => ''])
            ->assertOk()
            ->assertJsonPath('data.title', 'Tryout UTBK - SNBT');
    }

    public function test_reset_mengembalikan_seluruh_kartu_ke_bawaan(): void
    {
        TrackCard::create(['kategori' => 'cpns', 'title' => 'Judul lain', 'badge' => 'LABEL LAIN']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/track-cards/cpns/reset')
            ->assertOk()
            ->assertJsonPath('data.title', 'Tryout Sekolah Kedinasan & CPNS');

        $this->assertDatabaseCount('track_cards', 0);
    }

    public function test_lebih_dari_tiga_poin_ditolak(): void
    {
        $this->actingAs($this->admin())->putJson('/api/admin/track-cards/utbk', [
            'features' => ['satu', 'dua', 'tiga', 'empat'],
        ])->assertUnprocessable()->assertJsonValidationErrors('features');
    }

    public function test_kategori_selain_utbk_dan_cpns_tidak_ada(): void
    {
        // UrClass hanya punya dua kategori; alamat lain bukan kartu yang kosong,
        // melainkan kartu yang memang tidak ada.
        $this->actingAs($this->admin())
            ->putJson('/api/admin/track-cards/snbp', ['title' => 'Apa pun'])
            ->assertNotFound();
    }

    public function test_peserta_tidak_bisa_mengubah_kartu(): void
    {
        $siswa = User::factory()->create(['role' => 'user']);

        $this->actingAs($siswa)
            ->putJson('/api/admin/track-cards/utbk', ['title' => 'Diubah siswa'])
            ->assertForbidden();

        $this->getJson('/api/track-cards')->assertOk()
            ->assertJsonPath('data.0.title', 'Tryout UTBK - SNBT');
    }
}
