<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Facades\Storage;
use App\Models\UserTryoutAccess;

class Tryout extends Model
{
    use HasUlids;

    protected $fillable = [
        'title',
        'description',
        'image',
        'start_date',
        'end_date',
        'category',
        'kategori',
        'duration_minutes',
        'is_free',
        'discussion_requires_ticket',
        'use_irt',
        'randomize_options',
        'is_published',
        'created_by',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'is_published' => 'boolean',
        'is_free' => 'boolean',
        'discussion_requires_ticket' => 'boolean',
        'use_irt' => 'boolean',
        'randomize_options' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    /**
     * Apakah peserta harus membayar satu tiket untuk membuka pembahasan.
     *
     * Dua syarat, dan keduanya perlu: tryout berbayar sudah memasukkan
     * pembahasan ke dalam tiket yang dipakai mengerjakan, jadi menagih lagi
     * berarti menagih dua kali untuk hal yang sama.
     */
    public function pembahasanBerbayar(): bool
    {
        return $this->is_free && $this->discussion_requires_ticket;
    }

    public function getImageUrlAttribute()
    {
        if (! $this->image) {
            return null;
        }

        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return $this->image;
        }

        if (str_starts_with($this->image, '/') || str_starts_with($this->image, 'images/')) {
            return $this->image;
        }

        return url('storage/' . $this->image);
    }

    protected $appends = ['image_url'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accessCodes()
    {
        return $this->hasMany(AccessCode::class);
    }

    public function tryoutSubtests()
    {
        return $this->hasMany(TryoutSubtest::class);
    }

    public function sessions()
    {
        return $this->hasMany(TryoutSession::class);
    }

    public function userAccesses()
    {
        return $this->hasMany(UserTryoutAccess::class);
    }
}
