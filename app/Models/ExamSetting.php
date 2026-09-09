<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ExamSetting extends Model
{
    protected $table = 'pengaturan_ujian';

    public $incrementing = false;

    protected $fillable = ['skd_passing_grade_twk', 'skd_passing_grade_tiu', 'skd_passing_grade_tkp'];

    protected $attributes = [
        'id' => 1, 'skd_passing_grade_twk' => 65,
        'skd_passing_grade_tiu' => 80, 'skd_passing_grade_tkp' => 166,
    ];

    protected $casts = [
        'skd_passing_grade_twk' => 'integer', 'skd_passing_grade_tiu' => 'integer',
        'skd_passing_grade_tkp' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('global_exam_settings'));
        static::deleted(fn () => Cache::forget('global_exam_settings'));
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
