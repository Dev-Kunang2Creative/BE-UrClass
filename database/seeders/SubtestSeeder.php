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
            ['name' => 'Tes Wawasan Kebangsaan (TWK)',      'category' => 'TPS',     'exam_type' => 'cpns', 'max_questions' => 30],
            ['name' => 'Tes Intelegensi Umum (TIU)',        'category' => 'TPS',     'exam_type' => 'cpns', 'max_questions' => 35],
            ['name' => 'Tes Karakteristik Pribadi (TKP)',   'category' => 'TPS',     'exam_type' => 'cpns', 'max_questions' => 45],
        ];

        foreach ($items as $item) {
            $subtest = Subtest::updateOrCreate(
                ['name' => $item['name']],
                ['category' => $item['category'], 'exam_type' => $item['exam_type'], 'max_questions' => $item['max_questions']]
            );

            // TKP is scored per-option (1-5), not right/wrong. See ScoringService.
            $subtest->update([
                'scoring_scheme' => ScoringService::defaultSchemeFor($subtest),
            ]);
        }
    }
}