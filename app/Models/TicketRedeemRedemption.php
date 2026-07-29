<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TicketRedeemRedemption extends Model
{
    use HasUlids;

    protected $fillable = [
        'ticket_redeem_code_id',
        'user_id',
        'ticket_amount',
        'redeemed_at',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
    ];

    public function code()
    {
        return $this->belongsTo(TicketRedeemCode::class, 'ticket_redeem_code_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
