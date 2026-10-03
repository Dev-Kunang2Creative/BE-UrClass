<?php

namespace Tests\Feature;

use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Akun Instagram peserta: opsional, disimpan sebagai username baku, dan hanya
 * terlihat oleh admin.
 */
class AkunInstagramTest extends TestCase
{
    use RefreshDatabase;

    private function simpan(User $user, array $ubah = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->putJson('/api/profile/update', array_merge([
            'name' => 'Naniek Matanari',
            'phone_number' => '081234567890',
            'birth_date' => '2005-01-01',
            'gender' => 'P',
            'school_origin' => 'SMAN 1 Bekasi',
            'grade_level' => 'SMA/SMK Kelas 12',
            'target_university_1' => 'Universitas Indonesia',
            'target_major_1' => 'Akuntansi',
        ], $ubah));
    }

    private function peserta(array $atribut = []): User
    {
        return User::factory()->create(['kategori' => 'utbk', 'role' => 'user', ...$atribut]);
    }

    public function test_bentuk_apa_pun_yang_ditempel_disimpan_sebagai_username_baku(): void
    {
        foreach ([
            '@Nama.User' => 'nama.user',
            'nama_user' => 'nama_user',
            'https://www.instagram.com/nama_user/?igsh=MWx2Ymk' => 'nama_user',
            'instagram.com/Nama.User' => 'nama.user',
            '  @juara1  ' => 'juara1',
        ] as $masukan => $tersimpan) {
            $user = $this->peserta();

            $this->simpan($user, ['instagram' => $masukan])->assertOk();

            $this->assertSame($tersimpan, $user->fresh()->instagram, "masukan: {$masukan}");
        }
    }

    public function test_username_yang_tidak_mungkin_ada_di_instagram_ditolak(): void
    {
        foreach (['nama..user', '.nama', 'nama.', 'nama user', 'nama-user', str_repeat('a', 31)] as $buruk) {
            $this->simpan($this->peserta(), ['instagram' => $buruk])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('instagram');
        }
    }

    public function test_opsional_dan_bisa_dikosongkan(): void
    {
        $user = $this->peserta(['instagram' => 'lama']);

        // Klien lama yang belum mengirim kolom ini tidak menghapusnya.
        $this->simpan($user)->assertOk();
        $this->assertSame('lama', $user->fresh()->instagram);

        $this->simpan($user, ['instagram' => ''])->assertOk();
        $this->assertNull($user->fresh()->instagram);
    }

    public function test_admin_bisa_mencari_peserta_lewat_akun_instagramnya(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dicari = $this->peserta(['name' => 'Azima', 'instagram' => 'si.juara']);
        $this->peserta(['name' => 'Lain', 'instagram' => 'orang.lain']);

        $this->actingAs($admin)->getJson('/api/admin/users?search=@si.juara')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dicari->id)
            ->assertJsonPath('data.0.instagram', 'si.juara');
    }

    public function test_instagram_di_leaderboard_hanya_terlihat_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tryout = Tryout::create(['title' => 'Latihan', 'created_by' => $admin->id]);
        $juara = $this->peserta(['instagram' => 'si.juara']);
        TryoutSession::create([
            'tryout_id' => $tryout->id, 'user_id' => $juara->id,
            'attempt_number' => 1, 'status' => 'finished',
        ]);

        $url = "/api/tryouts/{$tryout->id}/leaderboard";

        $this->actingAs($admin)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.leaderboard.0.instagram', 'si.juara');

        $baris = $this->actingAs($this->peserta())->getJson($url)
            ->assertOk()
            ->json('data.leaderboard.0');

        $this->assertArrayNotHasKey('instagram', $baris);
    }
}
