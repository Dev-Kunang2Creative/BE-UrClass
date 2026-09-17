<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Testimonial extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'role',
        'program',
        'quote',
        'rating',
        'avatar',
        'avatar_bg',
        'color_theme',
        'order_no',
        'is_active',
    ];

    protected $casts = [
        'rating' => 'integer',
        'order_no' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'avatar_url',
    ];

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar) {
            return null;
        }

        if (filter_var($this->avatar, FILTER_VALIDATE_URL)) {
            return $this->avatar;
        }

        return Storage::disk('public')->url($this->avatar);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
