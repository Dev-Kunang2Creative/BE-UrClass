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
                'title'        => 'TO UTBK Sesi 1 - TPS & Literasi',
                'description'  => 'Tryout perdana Amunisi PTN. Latihan soal lengkap mencakup TPS (Tes Potensi Skolastik) dan Literasi untuk persiapan UTBK SNBT.',
                'start_date'   => now()->subDays(2),
                'end_date'     => now()->addDays(14),
                'category'     => 'UTBK',
                'is_published' => true,
            ],
            [
                'title'        => 'TO UTBK Sesi 2 - Penalaran & Kuantitatif',
                'description'  => 'Sesi kedua fokus pada Penalaran Umum dan Pengetahuan Kuantitatif. Dilengkapi timer per-subtest seperti ujian nyata.',
                'start_date'   => now()->addDays(7),
                'end_date'     => now()->addDays(28),
                'category'     => 'UTBK',
                'is_published' => true,
            ],
            [
                'title'        => 'TO Persiapan SNBP 2025',
                'description'  => 'Tryout khusus untuk calon peserta SNBP. Berisi soal-soal prediksi berdasarkan pola soal tahun-tahun sebelumnya.',
                'start_date'   => now()->addDays(30),
                'end_date'     => now()->addDays(60),
                'category'     => 'SNBP',
                'is_published' => false,
            ],
        ];

        $subtests = Subtest::all()->keyBy('name');

        foreach ($tryouts as $tryoutData) {
            $tryout = Tryout::updateOrCreate(
                ['title' => $tryoutData['title']],
                array_merge($tryoutData, ['created_by' => $adminId])
            );

            // Assign semua subtest ke tryout (jika belum ada)
            $order = 1;
            foreach ($subtests as $subtest) {
                TryoutSubtest::updateOrCreate(
                    ['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id],
                    ['duration_minutes' => 30, 'is_active' => true]
                );
                $order++;
            }
        }
    }
}
