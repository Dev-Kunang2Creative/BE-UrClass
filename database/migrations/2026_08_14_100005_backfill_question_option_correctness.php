<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill data lama: tandai opsi benar berdasarkan questions.correct_answer
 * dan beri score default 1 (skema right_wrong).
 * Portabel lintas driver (SQLite/MySQL) dan idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('questions')
            ->select('id', 'correct_answer')
            ->whereNotNull('correct_answer')
            ->where('correct_answer', '<>', '')
            ->orderBy('id')
            ->chunk(200, function ($questions) {
                foreach ($questions as $q) {
                    $key = strtoupper(trim((string) $q->correct_answer));

                    DB::table('question_options')
                        ->where('question_id', $q->id)
                        ->whereRaw('UPPER(TRIM(option_key)) = ?', [$key])
                        ->update(['is_correct' => 1, 'score' => 1]);
                }
            });
    }

    public function down(): void
    {
        DB::table('question_options')->update(['is_correct' => 0, 'score' => 0]);
    }
};
