<?php

namespace App\Support;

/**
 * Jenjang pendidikan peserta, sejajar dengan src/lib/jenjang.ts di frontend.
 *
 * Nilainya disimpan apa adanya di kolom `grade_level`, yang juga menampung
 * kelas untuk siswa SMA aktif ("SMA/SMK Kelas 12"). Konstanta di sini dipakai
 * untuk memutuskan kolom mana yang wajib menyertainya, bukan sebagai daftar
 * tertutup - kolomnya tetap string bebas supaya baris lama tidak mendadak
 * jadi tidak sah.
 */
class Jenjang
{
    public const SMA = 'SMA/SMK';

    /** Istilah jalur UTBK: lulusan SMA yang menunda kuliah untuk mengulang seleksi. */
    public const GAP_YEAR = 'Gap Year';

    /**
     * Istilah jalur CPNS untuk orang yang sama.
     *
     * Dibedakan dari Gap Year atas permintaan pengguna: di jalur CPNS, orang
     * yang sudah lulus SMA belum tentu sedang menunda kuliah - dan yang sudah
     * wisuda jelas bukan "gap year".
     */
    public const LULUSAN_SMA = 'Lulusan SMA/SMK';

    /** Jenjang yang mewajibkan jurusan. */
    public const PENDIDIKAN_TINGGI = ['D3', 'D4', 'S1', 'S2'];

    public static function butuhJurusan(?string $gradeLevel): bool
    {
        return in_array(trim((string) $gradeLevel), self::PENDIDIKAN_TINGGI, true);
    }

    /** Siswa SMA aktif menyertakan kelasnya, jadi nilainya berawalan "SMA/SMK". */
    public static function siswaSmaAktif(?string $gradeLevel): bool
    {
        return str_starts_with(trim((string) $gradeLevel), self::SMA);
    }
}
