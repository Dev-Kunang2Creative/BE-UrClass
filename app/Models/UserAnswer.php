<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAnswer extends Model
{
    protected $fillable = [
        'tryout_session_id',
        'question_id',
        'answer',
        'is_correct',
        'score',
    ];

    public function tryoutSession()
    {
        return $this->belongsTo(TryoutSession::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}