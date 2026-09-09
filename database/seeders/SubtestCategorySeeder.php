<?php

namespace Database\Seeders;

use App\Models\SubtestCategory;
use Illuminate\Database\Seeder;

class SubtestCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // UTBK
            [
                'code' => 'TPS',
                'name' => 'TPS',
                'exam_type' => 'utbk',
                'is_active' => true,
            ],
            [
                'code' => 'Literasi',
                'name' => 'Literasi',
                'exam_type' => 'utbk',
                'is_active' => true,
            ],

            // CPNS
            [
                'code' => 'TWK',
                'name' => 'TWK',
                'exam_type' => 'cpns',
                'is_active' => true,
            ],
            [
                'code' => 'TIU',
                'name' => 'TIU',
                'exam_type' => 'cpns',
                'is_active' => true,
            ],
            [
                'code' => 'TKP',
                'name' => 'TKP',
                'exam_type' => 'cpns',
                'is_active' => true,
            ],
        ];

        foreach ($categories as $cat) {
            SubtestCategory::updateOrCreate(
                ['code' => $cat['code']],
                $cat
            );
        }
    }
}
