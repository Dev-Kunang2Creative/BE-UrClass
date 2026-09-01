<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TryoutSession extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'tryout_id',
        'attempt_number',
        'started_at',
        'finished_at',
        'status',
        'total_score',
        'raw_score',
        'scoring_method',
        'score_finalized',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total_score' => 'decimal:2',
        'raw_score' => 'decimal:2',
        'score_finalized' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tryout()
    {
        return $this->belongsTo(Tryout::class);
    }

    public function answers()
    {
        return $this->hasMany(UserAnswer::class);
    }

    public function subtestSessions()
    {
        return $this->hasMany(TryoutSubtestSession::class);
    }
}
