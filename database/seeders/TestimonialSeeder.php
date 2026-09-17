<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Seeder;

class TestimonialSeeder extends Seeder
{
    public function run(): void
    {
        $testimonials = [
            [
                'name'        => 'Rizky Pratama',
                'role'        => 'Lolos FK UI 2024',
                'program'     => 'UTBK-SNBT',
                'quote'       => 'Timer per subtest di UrClass beneran ngelatih ketenangan pas ujian asli. Pembahasannya juga straight to the point.',
                'rating'      => 5,
                'avatar_bg'   => '#be185d',
                'color_theme' => 'pink',
                'order_no'    => 1,
                'is_active'   => true,
            ],
            [
                'name'        => 'Dina Kartika',
                'role'        => 'Lolos SKD Kemenkeu 2024',
                'program'     => 'CPNS',
                'quote'       => 'Simulasi CAT-nya mirip banget sama ujian BKN. Grafiknya ngebantu banget tahu kelemahan di bagian TWK.',
                'rating'      => 5,
                'avatar_bg'   => '#d97706',
                'color_theme' => 'yellow',
                'order_no'    => 2,
                'is_active'   => true,
            ],
            [
                'name'        => 'Ahmad Fauzi',
                'role'        => 'Lolos STEI ITB 2024',
                'program'     => 'UTBK-SNBT',
                'quote'       => 'Analisis butir soalnya rapi. Dari situ saya sadar harus perbanyak latihan di penalaran matematika.',
                'rating'      => 5,
                'avatar_bg'   => '#059669',
                'color_theme' => 'mint',
                'order_no'    => 3,
                'is_active'   => true,
            ],
            [
                'name'        => 'Nabila Zahra',
                'role'        => 'Lolos Hukum UGM 2024',
                'program'     => 'UTBK-SNBT',
                'quote'       => 'Platform tryout paling bersih dan nyaman. Nggak pusing lihat tampilannya, fokus ngerjain soal.',
                'rating'      => 5,
                'avatar_bg'   => '#2563eb',
                'color_theme' => 'blue',
                'order_no'    => 4,
                'is_active'   => true,
            ],
            [
                'name'        => 'Bima Arya',
                'role'        => 'Lolos SKD Kemenhub 2024',
                'program'     => 'CPNS',
                'quote'       => 'Paket Grade 2 ngebantu banget buat evaluasi berkala. Nilai TIU saya naik dari 120 jadi 160!',
                'rating'      => 5,
                'avatar_bg'   => '#7c3aed',
                'color_theme' => 'lavender',
                'order_no'    => 5,
                'is_active'   => true,
            ],
            [
                'name'        => 'Siti Rahma',
                'role'        => 'Lolos Farmasi Unair 2024',
                'program'     => 'UTBK-SNBT',
                'quote'       => 'Maskotnya nemenin tiap sesi latihan, bikin nggak jenuh belajar berjam-jam.',
                'rating'      => 5,
                'avatar_bg'   => '#475569',
                'color_theme' => 'cream',
                'order_no'    => 6,
                'is_active'   => true,
            ],
            [
                'name'        => 'Kevin Sanjaya',
                'role'        => 'Lolos Teknik Mesin ITS 2024',
                'program'     => 'UTBK-SNBT',
                'quote'       => 'Review jawabannya jelas dan ada waktu pengerjaan per soal. Bikin manajemen waktu makin matang.',
                'rating'      => 5,
                'avatar_bg'   => '#ca8a04',
                'color_theme' => 'yellow',
                'order_no'    => 7,
                'is_active'   => true,
            ],
            [
                'name'        => 'Putri Anggraini',
                'role'        => 'Lolos Kemlu 2024',
                'program'     => 'CPNS',
                'quote'       => 'Prediksi skor dan evaluasi passing grade-nya akurat. Bikin lebih percaya diri saat hari H.',
                'rating'      => 5,
                'avatar_bg'   => '#047857',
                'color_theme' => 'mint',
                'order_no'    => 8,
                'is_active'   => true,
            ],
        ];

        foreach ($testimonials as $data) {
            Testimonial::firstOrCreate(
                ['name' => $data['name'], 'role' => $data['role']],
                $data
            );
        }
    }
}
