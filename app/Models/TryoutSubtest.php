<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TryoutSubtest extends Model
{
    use HasUlids;

    protected $fillable = [
        'tryout_id',
        'subtest_id',
        'duration_minutes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tryout()
    {
        return $this->belongsTo(Tryout::class);
    }

    public function subtest()
    {
        return $this->belongsTo(Subtest::class);
    }

    public function subtestSessions()
    {
        return $this->hasMany(TryoutSubtestSession::class);
    }
}