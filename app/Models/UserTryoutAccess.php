<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class UserTryoutAccess extends Model
{
    use HasUlids;

    protected $table = 'user_tryout_access';

    // BRD: status seleksi peserta try out gratis
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_NEED_REVISION = 'need_revision';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const SELECTION_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_NEED_REVISION,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_SUBMITTED => 'Diajukan',
        self::STATUS_UNDER_REVIEW => 'Ditinjau',
        self::STATUS_NEED_REVISION => 'Perlu revisi',
        self::STATUS_ACCEPTED => 'Diterima',
        self::STATUS_REJECTED => 'Ditolak',
    ];

    protected $fillable = [
        'user_id',
        'tryout_id',
        'access_code_id',
        'proof_image',
        'proof_images',
        'proof_details',
        'discussion_unlocked',
        'granted_at',
        'selection_status',
        'selection_note',
        'selection_reviewed_at',
        'selection_reviewed_by',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'proof_images' => 'array',
        // Pasangan syarat dan berkasnya, supaya saat ditinjau kelihatan
        // tangkapan layar mana yang menjawab syarat mana.
        'proof_details' => 'array',
        'discussion_unlocked' => 'boolean',
        'selection_reviewed_at' => 'datetime',
    ];

    public function getSelectionStatusLabelAttribute(): ?string
    {
        return self::STATUS_LABELS[$this->selection_status] ?? null;
    }

    /** Peserta dianggap lolos seleksi gratis bila statusnya accepted. */
    public function isSelectionAccepted(): bool
    {
        return $this->selection_status === self::STATUS_ACCEPTED;
    }

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

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'selection_reviewed_by');
    }
}
