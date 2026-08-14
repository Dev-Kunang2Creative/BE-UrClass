<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Services\ScoringService;

class Subtest extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'category',
        'exam_type',
        'max_questions',
        'scoring_scheme',
        'score_correct',
        'score_wrong',
        'score_empty',
    ];

    protected $casts = [
        'score_correct' => 'decimal:2',
        'score_wrong' => 'decimal:2',
        'score_empty' => 'decimal:2',
    ];

    public function tryoutSubtests()
    {
        return $this->hasMany(TryoutSubtest::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order_no');
    }

    public function getEffectiveSchemeAttribute(): string
    {
        return ScoringService::schemeFor($this);
    }
}
