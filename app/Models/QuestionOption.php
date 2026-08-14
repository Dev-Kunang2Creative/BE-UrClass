<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionOption extends Model
{
    protected $fillable = [
        'question_id',
        'option_key',
        'option_text',
        'image',
        'score',
        'is_correct',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'is_correct' => 'boolean',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        return $this->image ? url('storage/' . $this->image) : null;
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
