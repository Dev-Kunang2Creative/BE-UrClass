<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class UserKelasEnrollment extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'kelas_id',
        'kelas_order_id',
        'enrolled_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class);
    }

    public function order()
    {
        return $this->belongsTo(KelasOrder::class, 'kelas_order_id');
    }
}
