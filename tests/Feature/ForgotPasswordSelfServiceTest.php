<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\NomorPonsel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reset password mandiri tanpa SMTP.
 *
 * Yang menahan penyalahgunaan di sini bukan kerahasiaan tanggal lahir dan
 * nomor ponsel - keduanya bisa diketahui orang terdekat - melainkan batas
 * percobaannya. Karena itu test yang menjaga batas dan keseragaman galat sama
 * pentingnya dengan test yang menjaga jalur bahagianya.
 */
class ForgotPasswordSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function peserta(array $atribut = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'siswa@urclass.test',
            'password' => 'password-lama',
            'birth_date' => '2005-04-17',
            'phone_number' => '081398169073',
            'role' => 'user',
        ], $atribut));
    }

    /**
     * $ip dipakai untuk menguji dua lapis batas secara terpisah: throttle rute
     * menghitung per IP, penghitung gagal menghitung per email.
     */
    private function minta(array $isi = [], ?string $ip = null): \Illuminate\Testing\TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $this->postJson('/api/auth/forgot-password', array_merge([
            'email' => 'siswa@urclass.test',
            'birth_date' => '2005-04-17',
            'phone_number' => '081398169073',
        ], $isi));
    }

    public function test_data_profil_yang_cocok_menghasilkan_token_dan_password_baru_berlaku(): void
    {
        $user = $this->peserta();

        $token = $this->minta()->assertOk()->json('data.token');
        $this->assertNotEmpty($token);

        // Yang tersimpan hash-nya, bukan tokennya.
        $baris = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotSame($token, $baris->token);
        $this->assertTrue(Hash::check($token, $baris->token));

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password-baru',
        ])->assertOk();
    }

    public function test_token_sekali_pakai_dan_seluruh_sesi_lama_dicabut(): void
    {
        $user = $this->peserta();
        $user->createToken('auth-token');
        $this->assertSame(1, $user->tokens()->count());

        $token = $this->minta()->assertOk()->json('data.token');
        $isi = [
            'email' => $user->email, 'token' => $token,
            'password' => 'password-baru', 'password_confirmation' => 'password-baru',
        ];

        $this->postJson('/api/auth/reset-password', $isi)->assertOk();
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertDatabaseCount('password_reset_tokens', 0);

        // Token yang sama tidak bisa dipakai lagi.
        $this->postJson('/api/auth/reset-password', $isi)->assertUnprocessable();
    }

    public function test_token_kedaluwarsa_setelah_lima_belas_menit(): void
    {
        $user = $this->peserta();
        $token = $this->minta()->assertOk()->json('data.token');

        $this->travel(16)->minutes();
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'password-baru', 'password_confirmation' => 'password-baru',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password-lama',
        ])->assertOk();
    }

    public function test_nomor_ponsel_dicocokkan_lepas_dari_cara_penulisannya(): void
    {
        $this->peserta(['phone_number' => '0813-9816-9073']);

        $this->minta(['phone_number' => '+62 813 9816 9073'])->assertOk();
        $this->assertSame('081398169073', NomorPonsel::normalkan('+62 813 9816 9073'));
        $this->assertSame('081398169073', NomorPonsel::normalkan('81398169073'));
        $this->assertSame('', NomorPonsel::normalkan(null));
    }

    public function test_profil_tanpa_data_pembanding_tidak_bisa_diverifikasi(): void
    {
        // Kolom kosong dibandingkan dengan kiriman kosong tidak boleh dianggap
        // cocok: akun yang profilnya paling telantar justru akan jadi yang
        // paling mudah diambil alih.
        $this->peserta(['birth_date' => null, 'phone_number' => null]);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'siswa@urclass.test', 'birth_date' => '2005-04-17', 'phone_number' => '0',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_galat_seragam_untuk_email_asing_dan_data_keliru(): void
    {
        $this->peserta();

        $asing = $this->minta(['email' => 'bukan-siapa-siapa@urclass.test'])->assertUnprocessable();
        $keliru = $this->minta(['birth_date' => '2001-01-01'])->assertUnprocessable();

        // Kalau kedua kalimat ini berbeda, endpoint-nya jadi alat memeriksa
        // email mana yang punya akun di UrClass.
        $this->assertSame(
            $asing->json('errors.email.0'),
            $keliru->json('errors.email.0'),
        );
    }

    public function test_admin_tidak_bisa_direset_lewat_jalur_mandiri(): void
    {
        $this->peserta(['email' => 'admin@urclass.test', 'role' => 'admin']);

        $this->minta(['email' => 'admin@urclass.test'])->assertUnprocessable();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_akun_dummy_bukan_sasaran_yang_sah(): void
    {
        $this->peserta(['email' => 'dummy@urclass.test', 'is_dummy' => true]);

        $this->minta(['email' => 'dummy@urclass.test'])->assertUnprocessable();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_lima_percobaan_gagal_mengunci_email_itu_walau_datanya_kemudian_benar(): void
    {
        $this->peserta();

        // Tiap percobaan dari IP berbeda, supaya yang diuji benar-benar
        // penghitung per email dan bukan throttle per IP di rutenya.
        for ($i = 0; $i < 5; $i++) {
            $this->minta(['birth_date' => '2001-01-0'.($i + 1)], '203.0.113.'.($i + 1))
                ->assertUnprocessable();
        }

        // Data yang benar pun ditolak: penghitungnya per email, jadi penebak
        // yang berpindah IP tetap tertahan.
        $terkunci = $this->minta([], '203.0.113.9')->assertUnprocessable();
        $this->assertStringContainsString('Terlalu banyak percobaan', $terkunci->json('errors.email.0'));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_satu_ip_kehabisan_jatah_walau_menyasar_email_berganti_ganti(): void
    {
        $this->peserta();

        // Lapis kedua: penghitung per email tidak menolong kalau penebaknya
        // berpindah-pindah sasaran, jadi rutenya juga dibatasi per IP.
        for ($i = 0; $i < 15; $i++) {
            $this->minta(['email' => "orang{$i}@urclass.test"], '198.51.100.7')
                ->assertUnprocessable();
        }

        $this->minta([], '198.51.100.7')->assertStatus(429);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }
}
