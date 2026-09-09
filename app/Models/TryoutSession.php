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
        'batas_waktu',
        'finished_at',
        'status',
        'total_score',
        'raw_score',
        'scoring_method',
        'score_finalized',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'batas_waktu' => 'datetime',
        'finished_at' => 'datetime',
        'total_score' => 'decimal:2',
        'raw_score' => 'decimal:2',
        'score_finalized' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function batasWaktuCpns(Tryout $tryout): \Illuminate\Support\Carbon
    {
        if (! $this->batas_waktu) {
            $durasi = $tryout->tryoutSubtests()->where('is_active', true)->sum('duration_minutes');
            $batas = ($this->started_at ?? now())->copy()->addMinutes((int) $durasi);
            static::whereKey($this->id)->whereNull('batas_waktu')->update(['batas_waktu' => $batas]);
            $this->refresh();
        }

        return $this->batas_waktu;
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
