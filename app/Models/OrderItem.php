<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class OrderItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'order_id',
        'package_id',
        'package_name_snapshot',
        'ticket_amount_snapshot',
        'price',
        'qty',
        'subtotal',
    ];

    protected $casts = [
        'price'                  => 'integer',
        'subtotal'               => 'integer',
        'qty'                    => 'integer',
        'ticket_amount_snapshot' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}