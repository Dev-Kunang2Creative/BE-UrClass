<?php

namespace App\Support;

/**
 * Menyamakan bentuk nomor ponsel Indonesia sebelum dibandingkan.
 *
 * Nomor yang sama ditulis bermacam-macam oleh pemiliknya sendiri:
 * "0813-9816-9073", "+6281398169073", "62 813 9816 9073", "81398169073".
 * Membandingkan mentah-mentah berarti pemilik akun yang sah gagal
 * memverifikasi dirinya hanya karena dulu mengetik nomornya dengan spasi.
 *
 * Hasilnya selalu bentuk lokal berawalan "0", atau string kosong kalau
 * masukannya tidak memuat angka sama sekali. Pemanggilnya wajib memperlakukan
 * string kosong sebagai "tidak bisa diverifikasi", bukan sebagai kecocokan -
 * dua nilai kosong yang dianggap cocok adalah cara termudah melewati
 * verifikasi tanpa tahu apa pun.
 */
class NomorPonsel
{
    /**
     * Bentuk baku untuk disimpan: +62 diikuti nomor tanpa angka nol di depan.
     *
     * `normalkan()` di bawah tetap menghasilkan bentuk lokal berawalan "0" dan
     * tetap dipakai untuk membandingkan - ia sengaja tidak diubah, karena
     * verifikasi lupa password membandingkan apa yang diketik peserta dengan
     * apa yang tersimpan, dan keduanya harus melewati fungsi yang sama.
     * Nomor lama yang tersimpan sebagai "08..." tetap cocok lewat jalur itu.
     *
     * Mengembalikan string kosong kalau masukannya tidak memuat angka sama
     * sekali, supaya pemanggilnya bisa membedakan "tidak diisi" dari "diisi".
     */
    public static function keBentukInternasional(?string $nomor): string
    {
        $lokal = self::normalkan($nomor);

        if ($lokal === '') {
            return '';
        }

        return '+62'.ltrim($lokal, '0');
    }

    public static function normalkan(?string $nomor): string
    {
        $angka = preg_replace('/\D+/', '', (string) $nomor) ?? '';

        if ($angka === '') {
            return '';
        }

        // Bentuk "0062..." dari penyalinan nomor internasional.
        if (str_starts_with($angka, '00')) {
            $angka = substr($angka, 2);
        }

        if (str_starts_with($angka, '62')) {
            return '0'.substr($angka, 2);
        }

        // Ditulis tanpa awalan apa pun, seperti "81398169073".
        if (str_starts_with($angka, '8')) {
            return '0'.$angka;
        }

        return $angka;
    }
}
