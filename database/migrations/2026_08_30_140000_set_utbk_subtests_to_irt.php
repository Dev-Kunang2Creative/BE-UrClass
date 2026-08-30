<?php

use App\Models\Subtest;
use App\Services\ScoringService;
use Illuminate\Database\Migrations\Migration;

/**
 * Subtes UTBK dinilai dengan IRT, bukan benar/salah berpoin tetap.
 *
 * Sebelumnya seluruh subtes UTBK tersimpan sebagai right_wrong dengan
 * score_correct = 1, seolah-olah admin menetapkan satu poin per jawaban benar.
 * Pada IRT tidak ada poin yang ditetapkan siapa pun: jawaban dicatat benar atau
 * salah, lalu bobot tiap soal dihitung dari hasil seluruh peserta. Kolom skor
 * dikembalikan ke nilai netral supaya tidak ada angka tersimpan yang tidak
 * dipakai perhitungan mana pun.
 *
 * Nilai yang tercatat di user_answers tidak diubah: pada right_wrong lama satu
 * jawaban benar bernilai 1, dan itu persis nilai yang dipakai skema IRT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Subtest::where('exam_type', 'utbk')->update([
            'scoring_scheme' => ScoringService::SCHEME_IRT,
            'score_correct'  => 1,
            'score_wrong'    => 0,
            'score_empty'    => 0,
        ]);
    }

    public function down(): void
    {
        Subtest::where('exam_type', 'utbk')->update([
            'scoring_scheme' => ScoringService::SCHEME_RIGHT_WRONG,
        ]);
    }
};
