<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TicketRedeemCode extends Model
{
    use HasUlids;

    protected $fillable = [
        'code',
        'ticket_amount',
        'quota',
        'used_count',
        'is_active',
        'expired_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expired_at' => 'datetime',
    ];

    public function redemptions()
    {
        return $this->hasMany(TicketRedeemRedemption::class);
    }

    public function isExpired(): bool
    {
        return $this->expired_at instanceof Carbon && $this->expired_at->isPast();
    }

    public function hasQuota(): bool
    {
        return $this->used_count < $this->quota;
    }
}
