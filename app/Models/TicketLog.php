<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TicketLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'source',
        'description',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
