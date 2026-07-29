<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class UserTryoutAccess extends Model
{
    use HasUlids;

    protected $table = 'user_tryout_access';

    protected $fillable = [
        'user_id',
        'tryout_id',
        'access_code_id',
        'proof_image',
        'proof_images',
        'discussion_unlocked',
        'granted_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'proof_images' => 'array',
        'discussion_unlocked' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tryout()
    {
        return $this->belongsTo(Tryout::class);
    }

    public function accessCode()
    {
        return $this->belongsTo(AccessCode::class);
    }
}
