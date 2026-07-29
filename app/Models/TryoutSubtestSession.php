<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TryoutSubtestSession extends Model
{
    use HasUlids;

    protected $fillable = [
        'tryout_session_id',
        'tryout_subtest_id',
        'started_at',
        'finished_at',
        'expired_at',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function tryoutSession()
    {
        return $this->belongsTo(TryoutSession::class);
    }

    public function tryoutSubtest()
    {
        return $this->belongsTo(TryoutSubtest::class);
    }
}