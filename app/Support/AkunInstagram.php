<?php

namespace App\Support;

/**
 * Username Instagram peserta, dalam satu bentuk baku.
 *
 * Peserta menempelkan apa saja yang ada di layarnya: "@nama.user", "nama.user",
 * atau tautan profil lengkap dengan "?igsh=..." dari tombol bagikan. Semuanya
 * berarti akun yang sama, jadi yang disimpan selalu username-nya saja - supaya
 * admin bisa mencarinya dan tautannya bisa dibentuk ulang tanpa menebak.
 *
 * Sejajar dengan lib/instagram.ts di frontend. Kalau keduanya menyimpang,
 * peserta akan lolos di layar lalu ditolak 422, atau sebaliknya.
 */
class AkunInstagram
{
    /**
     * Aturan username Instagram: 1-30 huruf kecil, angka, titik, atau garis
     * bawah; tidak diawali atau diakhiri titik, dan tanpa dua titik berurutan.
     */
    public const POLA = '/^(?!\.)(?!.*\.\.)[a-z0-9._]{1,30}(?<!\.)$/';

    public const PESAN = 'Username Instagram hanya boleh berisi huruf, angka, titik, dan garis bawah (maks. 30 karakter).';

    /** Mengembalikan username baku, atau null kalau tidak diisi. */
    public static function normalkan(?string $nilai): ?string
    {
        $teks = trim((string) $nilai);

        if (preg_match('~^(?:https?://)?(?:www\.|m\.)?(?:instagram\.com|instagr\.am)/([^/?#\s]+)~i', $teks, $cocok)) {
            $teks = $cocok[1];
        }

        $teks = strtolower(ltrim($teks, '@'));

        return $teks === '' ? null : $teks;
    }
}
