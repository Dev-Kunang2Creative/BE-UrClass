<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Subtest extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'category',
        'max_questions',
    ];

    public function tryoutSubtests()
    {
        return $this->hasMany(TryoutSubtest::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order_no');
    }
}