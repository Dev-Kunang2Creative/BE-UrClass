<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ExamSetting extends Model
{
    protected $table = 'pengaturan_ujian';

    public $incrementing = false;

    protected $fillable = ['skd_passing_grade_twk', 'skd_passing_grade_tiu', 'skd_passing_grade_tkp', 'formasi_ditampilkan'];

    protected $attributes = [
        'id' => 1, 'skd_passing_grade_twk' => 65,
        'skd_passing_grade_tiu' => 80, 'skd_passing_grade_tkp' => 166,
        'formasi_ditampilkan' => true,
    ];

    protected $casts = [
        'skd_passing_grade_twk' => 'integer', 'skd_passing_grade_tiu' => 'integer',
        'skd_passing_grade_tkp' => 'integer',
        'formasi_ditampilkan' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::lupakanCache());
        static::deleted(fn () => static::lupakanCache());
    }

    private static function lupakanCache(): void
    {
        Cache::forget('global_exam_settings');
        Cache::forget('pengaturan_formasi_ditampilkan');
    }

    /**
     * Apakah formasi boleh ditampilkan ke peserta. Saklar admin - lihat
     * migrasi add_formasi_ditampilkan. Dibaca di setiap pembukaan form profil
     * dan setiap penyimpanannya, jadi disimpan di cache seperti ambang SKD.
     */
    public static function formasiDitampilkan(): bool
    {
        return (bool) Cache::remember('pengaturan_formasi_ditampilkan', 300, function () {
            // `?? true`: selama migrasinya belum dijalankan, kolomnya belum ada
            // dan terbaca null. Menganggapnya mati akan menyembunyikan formasi
            // dari semua peserta di jeda antara deploy dan migrate - lalu
            // tertahan di cache ini lima menit setelahnya.
            return (static::find(1) ?? new static)->formasi_ditampilkan ?? true;
        });
    }

    public static function getSkdPassingGrades(): array
    {
        return Cache::remember('global_exam_settings', 300, function () {
            $pengaturan = static::find(1) ?? new static;

            return [
                'twk' => $pengaturan->skd_passing_grade_twk,
                'tiu' => $pengaturan->skd_passing_grade_tiu,
                'tkp' => $pengaturan->skd_passing_grade_tkp,
            ];
        });
    }
}
