<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Carbon;

class AccessCode extends Model
{
    use HasUlids;

    protected $fillable = [
        'code',
        'tryout_id',
        'max_usage',
        'used_count',
        'is_active',
        'expired_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expired_at' => 'datetime',
    ];

    public function tryout()
    {
        return $this->belongsTo(Tryout::class);
    }

    public function isExpired(): bool
    {
        return $this->expired_at instanceof Carbon && $this->expired_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->is_active &&
            ! $this->isExpired() &&
            $this->used_count < $this->max_usage;
    }
}