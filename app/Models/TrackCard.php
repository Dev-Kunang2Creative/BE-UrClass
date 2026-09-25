<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Teks satu kartu pemilihan jalur.
 *
 * Barisnya menyimpan perubahan admin saja; yang tidak diubah jatuh ke
 * self::BAWAAN. Karena itu `untuk()` selalu mengembalikan kartu yang utuh,
 * baik barisnya ada maupun belum pernah dibuat.
 */
class TrackCard extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'kategori';

    protected $fillable = [
        'kategori',
        'title',
        'badge',
        'cta',
        'description',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    /**
     * Teks pabrikan, sekaligus cadangan kalau kolomnya dikosongkan admin.
     *
     * Ini bukan "hardcode yang tersisa": admin boleh menimpa tiap kolomnya, dan
     * yang dikosongkan kembali ke nilai di sini alih-alih jadi kartu tanpa
     * judul. Kartu tanpa judul adalah satu-satunya keadaan yang tidak boleh
     * bisa dicapai lewat panel admin - peserta memakainya untuk memilih jalur.
     */
    public const BAWAAN = [
        'utbk' => [
            'title' => 'Tryout UTBK - SNBT',
            'badge' => 'PTN & GAP YEAR',
            'cta' => 'Masuk ke Tryout UTBK',
            'description' => 'Fokus latihan TPS, Literasi Bahasa Indonesia, Bahasa Inggris, dan Penalaran Matematika.',
            'features' => [
                'Simulasi timer standar SNBT resmi',
                'Analitik akurasi per subtest',
                'Pembahasan tuntas & kunci jawaban',
            ],
        ],
        'cpns' => [
            'title' => 'Tryout Sekolah Kedinasan & CPNS',
            'badge' => 'KEDINASAN & ASN',
            'cta' => 'Masuk ke Tryout Kedinasan & CPNS',
            'description' => 'Fokus latihan CAT SKD - TWK, TIU, dan TKP - untuk seleksi sekolah kedinasan maupun CPNS umum, dengan sistem bobot nilai akurat.',
            'features' => [
                'Simulasi CAT BKN realistis',
                'Sistem penilaian bobot TKP 1-5',
                'Ranking 3 level (Nasional/Daerah/Instansi)',
            ],
        ],
    ];

    public const KATEGORI = ['utbk', 'cpns'];

    /** Kartu utuh untuk satu jalur: bawaan, ditimpa perubahan yang tersimpan. */
    public static function untuk(string $kategori): array
    {
        $bawaan = self::BAWAAN[$kategori] ?? self::BAWAAN['utbk'];
        $tersimpan = self::find($kategori);

        if (! $tersimpan) {
            return ['kategori' => $kategori] + $bawaan;
        }

        $isi = [];
        foreach ($bawaan as $kolom => $nilaiBawaan) {
            $nilai = $tersimpan->{$kolom};
            // Kolom kosong berarti "pakai bawaan", bukan "kosongkan kartunya".
            $isi[$kolom] = ($nilai === null || $nilai === '' || $nilai === [])
                ? $nilaiBawaan
                : $nilai;
        }

        return ['kategori' => $kategori] + $isi;
    }

    /** @return array<int, array<string, mixed>> Kedua kartu, urut utbk lalu cpns. */
    public static function semua(): array
    {
        return array_map(fn ($kategori) => self::untuk($kategori), self::KATEGORI);
    }
}
