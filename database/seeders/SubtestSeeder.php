<?php

namespace Database\Seeders;

use App\Models\Subtest;
use App\Services\ScoringService;
use Illuminate\Database\Seeder;

class SubtestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $items = [
            // UTBK
            ['name' => 'Penalaran Umum',                    'category' => 'TPS',     'exam_type' => 'utbk', 'max_questions' => 30],
            ['name' => 'Pengetahuan dan Pemahaman Umum',    'category' => 'TPS',     'exam_type' => 'utbk', 'max_questions' => 20],
            ['name' => 'Pemahaman Bacaan dan Menulis',      'category' => 'TPS',     'exam_type' => 'utbk', 'max_questions' => 20],
            ['name' => 'Pengetahuan Kuantitatif',           'category' => 'TPS',     'exam_type' => 'utbk', 'max_questions' => 20],
            ['name' => 'Literasi dalam Bahasa Indonesia',   'category' => 'Literasi','exam_type' => 'utbk', 'max_questions' => 30],
            ['name' => 'Literasi dalam Bahasa Inggris',     'category' => 'Literasi','exam_type' => 'utbk', 'max_questions' => 20],
            ['name' => 'Penalaran Matematika',              'category' => 'Literasi','exam_type' => 'utbk', 'max_questions' => 20],
            // CPNS
            ['name' => 'Tes Wawasan Kebangsaan (TWK)',      'category' => 'TWK',     'exam_type' => 'cpns', 'max_questions' => 30],
            ['name' => 'Tes Intelegensi Umum (TIU)',        'category' => 'TIU',     'exam_type' => 'cpns', 'max_questions' => 35],
            ['name' => 'Tes Karakteristik Pribadi (TKP)',   'category' => 'TKP',     'exam_type' => 'cpns', 'max_questions' => 45],
        ];

        foreach ($items as $item) {
            $subtest = Subtest::updateOrCreate(
                ['name' => $item['name']],
                ['category' => $item['category'], 'exam_type' => $item['exam_type'], 'max_questions' => $item['max_questions']]
            );

            // Skemanya mengikuti jalur: UTBK selalu IRT, TKP bobot per opsi,
            // sisanya benar/salah. Lihat ScoringService::defaultSchemeFor.
            $scheme = ScoringService::defaultSchemeFor($subtest);

            $subtest->update([
                'scoring_scheme' => $scheme,
                // SKD gives 5 points per correct TWK/TIU answer, which is what
                // makes the passing grades (65 of 150, 80 of 175) mean anything.
                // Pada IRT tidak ada poin yang ditetapkan, jadi tetap 1.
                'score_correct' => $scheme === ScoringService::SCHEME_RIGHT_WRONG
                    ? ScoringService::CPNS_SCORE_CORRECT
                    : 1,
            ]);
        }
    }
}