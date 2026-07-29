<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Kelas extends Model
{
    use HasUlids;

    protected $table = 'kelas';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'discount_price',
        'ticket_amount',
        'wa_group_link',
        'wa_consultation_number',
        'meet_link',
        'image',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'price'          => 'integer',
        'discount_price' => 'integer',
        'ticket_amount'  => 'integer',
        'is_active'      => 'boolean',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if ($this->image) {
            return url('storage/' . $this->image);
        }

        return null;
    }

    public function enrollments()
    {
        return $this->hasMany(UserKelasEnrollment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orders()
    {
        return $this->hasMany(KelasOrder::class);
    }
}
