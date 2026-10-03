<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PromoBanner extends Model
{
    use HasUlids;

    /** 'semua' berarti banner tampil di kedua jalur. */
    public const KATEGORI = ['semua', 'utbk', 'cpns'];

    protected $fillable = ['image', 'alt', 'href', 'kategori', 'order_no', 'is_active'];

    protected $casts = ['order_no' => 'integer', 'is_active' => 'boolean'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return filter_var($this->image, FILTER_VALIDATE_URL)
            ? $this->image
            : Storage::disk('public')->url($this->image);
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    /** Banner jalur ini, ditambah yang berlaku untuk semua jalur. */
    public function scopeUntukJalur($query, ?string $kategori)
    {
        if (! in_array($kategori, ['utbk', 'cpns'], true)) {
            return $query;
        }

        return $query->whereIn('kategori', ['semua', $kategori]);
    }
}
