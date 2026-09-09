<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SubtestCategory extends Model
{
    use HasUlids;

    protected $fillable = [
        'code',
        'name',
        'exam_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForExamType($query, ?string $examType)
    {
        if (! $examType) {
            return $query;
        }

        return $query->where('exam_type', strtolower($examType));
    }
}
