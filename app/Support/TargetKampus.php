<?php

namespace App\Support;

use App\Models\PerguruanTinggi;

/**
 * Menjaga target kampus tetap berada di jalurnya.
 *
 * PTN dan sekolah kedinasan menempati satu tabel yang sama, dibedakan kolom
 * `jenis`. Endpoint daftarnya sudah menyaring menurut jenis, tapi penyaringan
 * itu hanya membentuk isi dropdown - ia tidak pernah menjadi penjagaan, karena
 * kolom referensi di UrClass memang wajib menerima ketikan manual. Peserta UTBK
 * yang mengetik "IPDN" sendiri, atau yang pindah jalur dari CPNS dengan target
 * lamanya masih tersimpan, lolos begitu saja sampai pemeriksaan ini ada.
 *
 * Yang diperiksa hanya nama yang benar-benar dikenal sebagai kampus jalur lain.
 * Nama yang tidak ada di tabel tetap diterima - dan itu disengaja: tabelnya
 * hanya memuat kampus negeri, jadi menolak semua yang tak dikenal akan
 * menghalangi peserta yang kampus tujuannya memang belum terdaftar. Data yang
 * hilang dan data yang salah jalur adalah dua hal berbeda; yang ditolak di sini
 * cuma yang kedua.
 */
class TargetKampus
{
    /** Jenis kampus yang sah untuk tiap jalur ujian. */
    private const JENIS_SAH = [
        'utbk' => 'ptn',
        'cpns' => 'kedinasan',
    ];

    public static function bertentangan(?string $nama, ?string $kategori): bool
    {
        $nama = trim((string) $nama);
        $jenisSah = self::JENIS_SAH[$kategori] ?? null;

        if ($nama === '' || $jenisSah === null) {
            return false;
        }

        // LOWER() eksplisit, bukan bersandar pada collation: MySQL produksi
        // membandingkan tanpa memandang besar-kecil huruf, SQLite di test tidak.
        return PerguruanTinggi::query()
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->where('jenis', '!=', $jenisSah)
            ->exists();
    }

    /** Kalimat penolakan, satu tempat supaya kedua kolom target sama bunyinya. */
    public static function pesanGalat(?string $kategori): string
    {
        return $kategori === 'cpns'
            ? 'Pilihan ini adalah perguruan tinggi negeri, bukan sekolah kedinasan. Pilih sekolah kedinasan untuk jalur CPNS.'
            : 'Pilihan ini adalah sekolah kedinasan, bukan PTN. Sekolah kedinasan hanya bisa dipilih di jalur CPNS.';
    }
}
