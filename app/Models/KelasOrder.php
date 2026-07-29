<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class KelasOrder extends Model
{
    use HasUlids;

    protected $table = 'kelas_orders';

    protected $fillable = [
        'order_code',
        'user_id',
        'kelas_id',
        'grand_total',
        'currency',
        'status',
        'midtrans_transaction_id',
        'payment_reference',
        'paid_at',
    ];

    protected $casts = [
        'grand_total' => 'integer',
        'paid_at'     => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class);
    }
}
