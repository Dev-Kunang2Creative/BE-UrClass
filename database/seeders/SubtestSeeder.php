<?php

namespace Database\Seeders;

use App\Models\Subtest;
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
            ['name' => 'Penalaran Umum',                    'category' => 'TPS',     'max_questions' => 30],
            ['name' => 'Pengetahuan dan Pemahaman Umum',    'category' => 'TPS',     'max_questions' => 20],
            ['name' => 'Pemahaman Bacaan dan Menulis',      'category' => 'TPS',     'max_questions' => 20],
            ['name' => 'Pengetahuan Kuantitatif',           'category' => 'TPS',     'max_questions' => 20],
            ['name' => 'Literasi dalam Bahasa Indonesia',   'category' => 'Literasi','max_questions' => 30],
            ['name' => 'Literasi dalam Bahasa Inggris',     'category' => 'Literasi','max_questions' => 20],
            ['name' => 'Penalaran Matematika',              'category' => 'Literasi','max_questions' => 20],
        ];

        foreach ($items as $item) {
            Subtest::updateOrCreate(
                ['name' => $item['name']],
                ['category' => $item['category'], 'max_questions' => $item['max_questions']]
            );
        }
    }
}