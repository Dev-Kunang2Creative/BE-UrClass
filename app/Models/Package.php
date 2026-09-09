<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $kategori Legacy per-track tag. Nothing filters on it any
 *   more: the catalogue serves both jalur because a ticket is a single balance
 *   (users.ticket_balance) that works on either. Kept for existing rows and
 *   reporting - do not reintroduce it as an access rule.
 */
class Package extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'thumbnail',
        'price',
        'discount_price',
        'ticket_amount',
        'currency',
        'kategori',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'price' => 'integer',
        'discount_price' => 'integer',
        'ticket_amount' => 'integer',
        'is_active' => 'boolean',
    ];

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function enrollments()
    {
        return $this->hasMany(UserPackageEnrollment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tryouts()
    {
        return $this->belongsToMany(Tryout::class, 'package_tryout');
    }
}