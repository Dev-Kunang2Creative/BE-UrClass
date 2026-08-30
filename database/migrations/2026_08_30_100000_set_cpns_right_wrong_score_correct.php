<?php

use App\Models\Subtest;
use App\Services\ScoringService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TWK dan TIU SKD bernilai 5 per soal benar, bukan 1.
 *
 * Kolom score_correct dibuat dengan default 1 dan tidak pernah disetel ke nilai
 * SKD, sehingga TWK sempurna berhenti di 30 dan TIU di 35. Halaman hasil
 * membandingkannya dengan Passing Grade KepmenPAN-RB (TWK 65, TIU 80), jadi
 * peserta selalu dinyatakan tidak lulus berapa pun jawaban benarnya.
 *
 * Jawaban yang sudah tersimpan ikut diskalakan. Skor mentah satu sesi dibaca
 * dari user_answers.score sementara skor maksimumnya dihitung ulang dari
 * konfigurasi subtes, jadi menaikkan yang satu tanpa yang lain justru membuat
 * hasil lama makin salah.
 */
return new class extends Migration
{
    public function up(): void
    {
        $subtests = Subtest::where('exam_type', 'cpns')
            ->where('scoring_scheme', ScoringService::SCHEME_RIGHT_WRONG)
            // Hanya yang masih memakai nilai default. Angka yang sudah sengaja
            // diubah admin bukan urusan migrasi ini.
            ->where('score_correct', 1)
            ->get();

        foreach ($subtests as $subtest) {
            $subtest->update([
                'score_correct' => ScoringService::CPNS_SCORE_CORRECT,
            ]);

            DB::table('user_answers')
                ->whereIn('question_id', function ($query) use ($subtest) {
                    $query->select('id')
                        ->from('questions')
                        ->where('subtest_id', $subtest->id);
                })
                ->where('is_correct', 1)
                ->where('score', 1)
                ->update(['score' => ScoringService::CPNS_SCORE_CORRECT]);
        }
    }

    public function down(): void
    {
        $subtests = Subtest::where('exam_type', 'cpns')
            ->where('scoring_scheme', ScoringService::SCHEME_RIGHT_WRONG)
            ->where('score_correct', ScoringService::CPNS_SCORE_CORRECT)
            ->get();

        foreach ($subtests as $subtest) {
            $subtest->update(['score_correct' => 1]);

            DB::table('user_answers')
                ->whereIn('question_id', function ($query) use ($subtest) {
                    $query->select('id')
                        ->from('questions')
                        ->where('subtest_id', $subtest->id);
                })
                ->where('is_correct', 1)
                ->where('score', ScoringService::CPNS_SCORE_CORRECT)
                ->update(['score' => 1]);
        }
    }
};
