<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'subtest_id',
        'question_type',
        'question_text',
        'question_image',
        'discussion',
        'discussion_image',
        'correct_answer',
        'order_no',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'question_image_url', 
        'discussion_image_url'
    ];

    public function getQuestionImageUrlAttribute()
    {
        return $this->question_image ? url('storage/' . $this->question_image) : null;
    }

    public function getDiscussionImageUrlAttribute()
    {
        return $this->discussion_image ? url('storage/' . $this->discussion_image) : null;
    }

    public function subtest()
    {
        return $this->belongsTo(Subtest::class);
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class)->orderBy('option_key');
    }

    public function userAnswers()
    {
        return $this->hasMany(UserAnswer::class);
    }
}
