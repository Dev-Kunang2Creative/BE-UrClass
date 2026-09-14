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
