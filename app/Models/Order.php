<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Order extends Model
{
    use HasUlids;

    protected $fillable = [
        'order_code',
        'user_id',
        'grand_total',
        'currency',
        'status',
        'payment_method',
        'payment_reference',
        'midtrans_transaction_id',
        'midtrans_order_id',
        'paid_at',
        'approved_at',
        'approved_by',
        'admin_note',
    ];

    protected $casts = [
        'grand_total' => 'integer',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}