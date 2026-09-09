<?php

namespace Database\Seeders;

use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSubtest;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample CPNS content so the CPNS dashboard is not empty.
 * UTBK content predates the kategori column and defaults to 'utbk'.
 *
 * Tryouts and subtests only. This used to seed two SKD ticket packages as well,
 * but packages are not per-track: a ticket is one balance spendable on either
 * jalur, so the catalogue lives entirely in PackageSeeder now.
 */
class CpnsContentSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::where('role', 'admin')->first()?->id;

        // SKD subtests already exist (seeded with exam_type=cpns); reuse them.
        $skdSubtests = Subtest::where('exam_type', 'cpns')->get();

        $tryouts = [
            [
                'title'        => 'TO CPNS SKD #1 - TWK, TIU & TKP',
                'description'  => 'Simulasi Seleksi Kompetensi Dasar sesuai kisi-kisi terbaru: Tes Wawasan Kebangsaan, Tes Intelegensi Umum, dan Tes Karakteristik Pribadi.',
                'start_date'   => now()->subDay(),
                'end_date'     => now()->addDays(21),
                'is_published' => true,
            ],
            [
                'title'        => 'TO CPNS SKD #2 - Simulasi Passing Grade',
                'description'  => 'Latihan dengan ambang batas nilai per subtes seperti ujian CAT BKN yang sebenarnya.',
                'start_date'   => now()->addDays(5),
                'end_date'     => now()->addDays(35),
                'is_published' => true,
            ],
        ];

        foreach ($tryouts as $data) {
            $tryout = Tryout::updateOrCreate(
                ['title' => $data['title']],
                array_merge($data, [
                    'category'   => 'CPNS',
                    'kategori'   => 'cpns',
                    'created_by' => $adminId,
                ])
            );

            foreach ($skdSubtests as $subtest) {
                TryoutSubtest::updateOrCreate(
                    ['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id],
                    ['duration_minutes' => 30, 'is_active' => true]
                );
            }
        }
    }
}
