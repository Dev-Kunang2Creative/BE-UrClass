<?php

namespace App\Support;

/**
 * Pola masukan yang dipakai bersama form profil dan pendaftaran.
 *
 * Sebelumnya satu-satunya penjagaan adalah "tidak boleh ada < dan >", yang
 * memang menahan tag HTML tetapi meloloskan "naniek matanari@^^^^" sebagai nama
 * dan deretan 30 angka sebagai nomor HP. Nama ikut tercetak di sertifikat dan
 * nomor HP dipakai menghubungi peserta, jadi keduanya perlu bentuk yang benar,
 * bukan sekadar bebas dari tag.
 *
 * Satu tempat, supaya server dan form di frontend tidak menyimpang - kalau
 * keduanya berbeda, peserta akan lolos di layar lalu ditolak 422, atau
 * sebaliknya ditolak di layar untuk sesuatu yang sebenarnya diterima server.
 */
class AturanMasukan
{
    /**
     * Nama orang: huruf apa pun (termasuk beraksen), spasi, titik, tanda hubung.
     *
     * Wajib diawali huruf, jadi "@^^^^" atau "123" tertolak sejak karakter
     * pertama. Titik untuk singkatan seperti "M. Rizki", tanda hubung untuk
     * nama majemuk seperti "Nur-Aini". Tanda petik sengaja tidak diizinkan
     * karena diminta begitu; kalau suatu saat ada nama yang memerlukannya,
     * tambahkan "'" ke dalam kurung siku kedua di sini dan di lib input-rules.ts
     * milik frontend.
     */
    public const NAMA = '/^\p{L}[\p{L} .\-]*$/u';

    public const NAMA_MAKS = 100;

    /**
     * Nomor ponsel Indonesia dalam bentuk baku +62.
     *
     * "+62" lalu "8" lalu 8-12 angka: total 11-15 digit terhitung kode negara,
     * yang juga batas atas E.164. Diawali 8 karena ini kolom nomor HP - nomor
     * rumah berawalan kode area tidak bisa dihubungi lewat WhatsApp.
     *
     * Yang disimpan selalu bentuk ini. Masukan "08...", "62...", atau "8..."
     * dinormalkan lebih dulu oleh NomorPonsel::keBentukInternasional().
     */
    public const TELEPON = '/^\+628\d{8,12}$/';

    /**
     * Teks bebas pendek: asal sekolah, jenjang, dan sejenisnya.
     *
     * Lebih longgar daripada nama karena nama sekolah memuat angka dan garis
     * miring ("SMAN 1 Bekasi", "SMK 2 Bandung", "MA Al-Hikmah"), tapi tetap
     * menolak karakter yang tidak punya alasan berada di sana.
     */
    public const TEKS_PENDEK = '/^[\p{L}\d][\p{L}\d\s.,\'\-\/()]*$/u';

    /** @return array<string, string> Pesan galat, sejajar dengan pola di atas. */
    public static function pesan(): array
    {
        return [
            'name.regex' => 'Nama hanya boleh berisi huruf, spasi, titik, dan tanda hubung.',
            'name.max' => 'Nama maksimal '.self::NAMA_MAKS.' karakter.',
            'phone_number.regex' => 'Nomor HP harus diawali +62 dan terdiri dari 11-15 angka.',
            'school_origin.regex' => 'Asal sekolah mengandung karakter yang tidak diperbolehkan.',
            'grade_level.regex' => 'Kelas mengandung karakter yang tidak diperbolehkan.',
        ];
    }
}
