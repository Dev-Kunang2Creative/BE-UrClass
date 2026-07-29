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
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
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
