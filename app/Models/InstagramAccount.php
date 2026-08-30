<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu akun Instagram yang wajib di-follow peserta tryout gratis.
 *
 * Username disimpan tanpa "@" dan tanpa URL supaya hanya ada satu bentuk yang
 * tersimpan; tampilan dan tautannya diturunkan dari situ.
 */
class InstagramAccount extends Model
{
    use HasUlids;

    protected $fillable = [
        'username',
        'label',
        'order_no',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order_no' => 'integer',
    ];

    protected $appends = ['profile_url'];

    public function getProfileUrlAttribute(): string
    {
        return 'https://www.instagram.com/' . $this->username . '/';
    }

    /** Bentuk baku username: tanpa "@", tanpa spasi, huruf kecil. */
    public static function normaliseUsername(string $value): string
    {
        return strtolower(trim(ltrim(trim($value), '@')));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order_no')->orderBy('username');
    }
}
