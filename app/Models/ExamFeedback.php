<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamFeedback extends Model
{
    use HasUlids;

    protected $table = 'exam_feedbacks';

    public const CATEGORIES = ['kualitas_soal', 'kesesuaian_waktu', 'sistem_ui', 'kunci_jawaban', 'lainnya'];

    protected $fillable = ['user_id', 'tryout_id', 'rating', 'category', 'comment'];

    protected $casts = ['rating' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tryout(): BelongsTo
    {
        return $this->belongsTo(Tryout::class);
    }
}
