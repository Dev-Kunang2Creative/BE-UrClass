<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class AuditLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'user_name',
        'module',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'ip_address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
