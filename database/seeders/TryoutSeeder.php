<?php

namespace Database\Seeders;

use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSubtest;
use App\Models\User;
use Illuminate\Database\Seeder;

class TryoutSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $adminId = $admin?->id;

        $tryouts = [
            [
                'title'        => 'TO UTBK SNBT Series #01 - TPS & Literasi',
                'description'  => 'Tryout perdana UrClass. Latihan soal lengkap mencakup TPS (Tes Potensi Skolastik) dan Literasi untuk persiapan UTBK SNBT.',
                'start_date'   => now()->subDays(2),
                'end_date'     => now()->addDays(14),
                'category'     => 'UTBK',
                'kategori'     => 'utbk',
                'is_free'      => true,
                'use_irt'      => true,
                'is_published' => true,
            ],
            [
                'title'        => 'TO UTBK SNBT Series #02 - Penalaran & Kuantitatif',
                'description'  => 'Sesi kedua fokus pada Penalaran Umum dan Pengetahuan Kuantitatif. Dilengkapi timer per-subtest seperti ujian nyata.',
                'start_date'   => now()->addDays(7),
                'end_date'     => now()->addDays(28),
                'category'     => 'UTBK',
                'kategori'     => 'utbk',
                'is_free'      => false,
                'use_irt'      => true,
                'is_published' => true,
            ],
            [
                'title'        => 'Tryout SKD CPNS Series #01',
                'description'  => 'Simulasi CAT SKD CPNS perdana UrClass: TWK, TIU, dan TKP sesuai standar Passing Grade resmi KepmenPAN-RB.',
                'start_date'   => now()->subDays(1),
                'end_date'     => now()->addDays(21),
                'category'     => 'CPNS',
                'kategori'     => 'cpns',
                'is_free'      => true,
                'use_irt'      => false,
                'is_published' => true,
            ],
            [
                'title'        => 'Tryout SKD CPNS Series #02',
                'description'  => 'Latihan intensif SKD CPNS dengan pembobotan soal dan perankingan nasional.',
                'start_date'   => now()->addDays(5),
                'end_date'     => now()->addDays(35),
                'category'     => 'CPNS',
                'kategori'     => 'cpns',
                'is_free'      => false,
                'use_irt'      => false,
                'is_published' => true,
            ],
            [
                'title'        => 'Tryout Kedinasan Series #01',
                'description'  => 'Persiapan seleksi masuk Sekolah Kedinasan (IPDN, STAN, STIS, Poltekip, Poltekim, dll).',
                'start_date'   => now()->addDays(10),
                'end_date'     => now()->addDays(40),
                'category'     => 'CPNS',
                'kategori'     => 'cpns',
                'is_free'      => false,
                'use_irt'      => false,
                'is_published' => true,
            ],
        ];

        foreach ($tryouts as $tryoutData) {
            $tryout = Tryout::updateOrCreate(
                ['title' => $tryoutData['title']],
                array_merge($tryoutData, ['created_by' => $adminId])
            );

            // Only subtests of the same exam track belong in this tryout.
            $subtests = Subtest::where('exam_type', $tryoutData['kategori'])->get();

            foreach ($subtests as $subtest) {
                TryoutSubtest::updateOrCreate(
                    ['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id],
                    ['duration_minutes' => 30, 'is_active' => true]
                );
            }
        }
    }
}
