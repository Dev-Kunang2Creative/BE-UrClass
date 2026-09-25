<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\NomorPonsel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batasan bentuk untuk nama dan nomor HP.
 *
 * Sebelumnya satu-satunya penjagaan adalah "tidak boleh ada < dan >", sehingga
 * "naniek matanari@^^^^" tersimpan sebagai nama dan deretan 30 angka tersimpan
 * sebagai nomor HP. Nama ikut tercetak di sertifikat dan nomor HP dipakai
 * menghubungi peserta, jadi keduanya perlu bentuk yang benar.
 */
class AturanMasukanProfilTest extends TestCase
{
    use RefreshDatabase;

    private function profil(array $ubah = []): array
    {
        return array_merge([
            'name' => 'Naniek Matanari',
            'phone_number' => '081234567890',
            'birth_date' => '2006-01-01',
            'gender' => 'P',
            'school_origin' => 'SMAN 1 Bekasi',
            'grade_level' => 'Kelas 12',
            'target_university_1' => 'Universitas Indonesia',
            'target_major_1' => 'Ilmu Komputer',
        ], $ubah);
    }

    private function simpan(array $ubah = []): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create(['kategori' => 'utbk', 'role' => 'user']);

        return $this->actingAs($user)->putJson('/api/profile/update', $this->profil($ubah));
    }

    public function test_nama_bersimbol_ditolak(): void
    {
        foreach (['naniek matanari@^^^^', 'Budi & Ani', 'Rizki$', 'Siswa "Hebat"', "O'Brien", 'Siswa123'] as $nama) {
            $this->simpan(['name' => $nama])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }
    }

    public function test_nama_wajar_tetap_diterima(): void
    {
        // Titik untuk singkatan, tanda hubung untuk nama majemuk, huruf beraksen
        // untuk nama yang memang ditulis begitu.
        foreach (['Naniek Matanari', 'M. Rizki Ramadhan', 'Nur-Aini', 'José Mourinho'] as $nama) {
            $this->simpan(['name' => $nama])->assertOk();
        }
    }

    public function test_nama_dibatasi_seratus_karakter(): void
    {
        $this->simpan(['name' => str_repeat('a', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->simpan(['name' => str_repeat('a', 100)])->assertOk();
    }

    public function test_nomor_hp_kepanjangan_ditolak(): void
    {
        // Persis kasus di tangkapan layar: deretan angka tanpa batas.
        $this->simpan(['phone_number' => '123456789444444444444444444444'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone_number');
    }

    public function test_nomor_hp_yang_bukan_nomor_ponsel_ditolak(): void
    {
        foreach (['0211234567', '+62211234567', '08123', 'abcdefghij', '+1 202 555 0143'] as $nomor) {
            $this->simpan(['phone_number' => $nomor])
                ->assertUnprocessable()->assertJsonValidationErrors('phone_number');
        }
    }

    public function test_nomor_hp_disimpan_dalam_satu_bentuk_apa_pun_cara_mengetiknya(): void
    {
        foreach ([
            '081234567890',
            '+6281234567890',
            '6281234567890',
            '81234567890',
            '0812-3456-7890',
            '+62 812 3456 7890',
        ] as $ditulis) {
            $user = User::factory()->create(['kategori' => 'utbk', 'role' => 'user']);
            $this->actingAs($user)
                ->putJson('/api/profile/update', $this->profil(['phone_number' => $ditulis]))
                ->assertOk();

            $this->assertSame('+6281234567890', $user->fresh()->phone_number, "gagal untuk: {$ditulis}");
        }
    }

    public function test_nomor_lama_berbentuk_nol_tetap_cocok_saat_lupa_password(): void
    {
        // Bentuk simpan berubah jadi +62, tapi pencocokan lupa password memakai
        // normalkan() yang meratakan keduanya - akun lama tidak ikut terkunci.
        $this->assertSame(
            NomorPonsel::normalkan('081234567890'),
            NomorPonsel::normalkan('+6281234567890'),
        );
    }

    public function test_nama_bersimbol_ditolak_saat_mendaftar_akun(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'naniek matanari@^^^^',
            'email' => 'baru@urclass.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
