<?php

namespace Tests\Feature;

use App\Models\PromoBanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Banner promosi yang dikelola admin, dengan klasifikasi per jalur.
 *
 * Sebelumnya enam banner ditulis mati di komponen dan semuanya tampil di kedua
 * jalur - banner bertema UTBK ikut muncul di hadapan peserta CPNS.
 */
class PromoBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function banner(array $ubah = []): PromoBanner
    {
        return PromoBanner::create(array_merge([
            'image' => 'promo-banners/contoh.webp',
            'alt' => 'Banner contoh',
            'href' => '/dashboard/try-out',
            'kategori' => 'semua',
            'order_no' => 0,
            'is_active' => true,
        ], $ubah));
    }

    public function test_peserta_hanya_melihat_banner_jalurnya_sendiri(): void
    {
        $this->banner(['alt' => 'Khusus UTBK', 'kategori' => 'utbk']);
        $this->banner(['alt' => 'Khusus CPNS', 'kategori' => 'cpns']);
        $this->banner(['alt' => 'Berlaku semua', 'kategori' => 'semua']);

        $pesertaCpns = User::factory()->create(['kategori' => 'cpns']);
        $daftar = $this->actingAs($pesertaCpns)->getJson('/api/promo-banners')
            ->assertOk()->json('data.*.alt');

        $this->assertContains('Khusus CPNS', $daftar);
        $this->assertContains('Berlaku semua', $daftar);
        $this->assertNotContains('Khusus UTBK', $daftar);
    }

    public function test_banner_nonaktif_tidak_pernah_tampil(): void
    {
        $this->banner(['alt' => 'Sudah lewat', 'is_active' => false]);

        $peserta = User::factory()->create(['kategori' => 'utbk']);
        $this->actingAs($peserta)->getJson('/api/promo-banners')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_urutannya_mengikuti_nomor_yang_disetel_admin(): void
    {
        $this->banner(['alt' => 'Ketiga', 'order_no' => 3]);
        $this->banner(['alt' => 'Pertama', 'order_no' => 1]);
        $this->banner(['alt' => 'Kedua', 'order_no' => 2]);

        $peserta = User::factory()->create(['kategori' => 'utbk']);
        $this->assertSame(
            ['Pertama', 'Kedua', 'Ketiga'],
            $this->actingAs($peserta)->getJson('/api/promo-banners')->json('data.*.alt'),
        );
    }

    public function test_admin_mengunggah_banner_baru(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/api/admin/promo-banners', [
            'image' => UploadedFile::fake()->image('promo.webp'),
            'alt' => 'Promo tryout hemat',
            'kategori' => 'cpns',
            'order_no' => 1,
        ])->assertCreated();

        $banner = PromoBanner::query()->firstOrFail();
        Storage::disk('public')->assertExists($banner->image);
        $this->assertStringContainsString('/storage/promo-banners/', $response->json('data.image_url'));
    }

    public function test_mengganti_gambar_menghapus_berkas_lamanya(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/api/admin/promo-banners', [
            'image' => UploadedFile::fake()->image('lama.webp'),
            'alt' => 'Banner', 'kategori' => 'semua',
        ])->assertCreated();

        $banner = PromoBanner::query()->firstOrFail();
        $jalurLama = $banner->image;

        $this->post("/api/admin/promo-banners/{$banner->id}", [
            'image' => UploadedFile::fake()->image('baru.webp'),
            'alt' => 'Banner', 'kategori' => 'semua',
        ])->assertOk();

        Storage::disk('public')->assertMissing($jalurLama);
        Storage::disk('public')->assertExists($banner->fresh()->image);
    }

    public function test_menghapus_banner_ikut_menghapus_berkasnya(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/api/admin/promo-banners', [
            'image' => UploadedFile::fake()->image('promo.webp'),
            'alt' => 'Banner', 'kategori' => 'semua',
        ])->assertCreated();

        $banner = PromoBanner::query()->firstOrFail();
        $jalur = $banner->image;

        $this->deleteJson("/api/admin/promo-banners/{$banner->id}")->assertOk();

        Storage::disk('public')->assertMissing($jalur);
        $this->assertDatabaseCount('promo_banners', 0);
    }

    public function test_tautan_keluar_aplikasi_ditolak(): void
    {
        // Banner tidak boleh jadi jalan mengarahkan peserta ke luar.
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/api/admin/promo-banners', [
            'image' => UploadedFile::fake()->image('promo.webp'),
            'alt' => 'Banner', 'href' => 'https://situs-lain.example',
        ])->assertUnprocessable()->assertJsonValidationErrors('href');
    }

    public function test_peserta_tidak_bisa_mengelola_banner(): void
    {
        $siswa = User::factory()->create(['role' => 'user']);

        $this->actingAs($siswa)->post('/api/admin/promo-banners', [
            'image' => UploadedFile::fake()->image('promo.webp'),
            'alt' => 'Banner siswa',
        ])->assertForbidden();
    }
}
