<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        $sampleQuestions = [
            'Penalaran Umum' => [
                [
                    'question_text' => 'Jika semua A adalah B, dan semua B adalah C, maka...',
                    'options' => [
                        'A' => 'Semua C adalah A',
                        'B' => 'Semua A adalah C',
                        'C' => 'Tidak ada A yang C',
                        'D' => 'Sebagian B bukan C',
                        'E' => 'Semua C adalah B',
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Premis 1: Semua A adalah B. Premis 2: Semua B adalah C. Kesimpulan silogisme: Semua A adalah C.',
                ],
                [
                    'question_text' => 'Seorang petani memiliki 3 kali lebih banyak sapi daripada kambing. Jika jumlah total hewan adalah 48, berapa jumlah sapi?',
                    'options' => [
                        'A' => '12',
                        'B' => '24',
                        'C' => '36',
                        'D' => '16',
                        'E' => '32',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Misal kambing = x, sapi = 3x. Total: x + 3x = 48, maka x = 12 (kambing), sapi = 36.',
                ],
                [
                    'question_text' => 'Dalam suatu barisan angka 2, 4, 8, 16, ..., angka berikutnya adalah...',
                    'options' => [
                        'A' => '24',
                        'B' => '28',
                        'C' => '30',
                        'D' => '32',
                        'E' => '36',
                    ],
                    'correct_answer' => 'D',
                    'discussion' => 'Barisan ini adalah deret geometri dengan rasio 2. Setelah 16, angka berikutnya adalah 16 × 2 = 32.',
                ],
            ],
            'Pengetahuan dan Pemahaman Umum' => [
                [
                    'question_text' => 'Siapakah presiden pertama Republik Indonesia?',
                    'options' => [
                        'A' => 'Mohammad Hatta',
                        'B' => 'Soeharto',
                        'C' => 'Soekarno',
                        'D' => 'Habibie',
                        'E' => 'Megawati',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Ir. Soekarno adalah presiden pertama Republik Indonesia, menjabat dari 1945 hingga 1967.',
                ],
                [
                    'question_text' => 'Tahun berapakah Indonesia memproklamasikan kemerdekaannya?',
                    'options' => [
                        'A' => '1942',
                        'B' => '1943',
                        'C' => '1944',
                        'D' => '1945',
                        'E' => '1946',
                    ],
                    'correct_answer' => 'D',
                    'discussion' => 'Indonesia memproklamasikan kemerdekaan pada tanggal 17 Agustus 1945.',
                ],
                [
                    'question_text' => 'Apa nama ibu kota provinsi Jawa Tengah?',
                    'options' => [
                        'A' => 'Surabaya',
                        'B' => 'Yogyakarta',
                        'C' => 'Semarang',
                        'D' => 'Solo',
                        'E' => 'Magelang',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Semarang adalah ibu kota provinsi Jawa Tengah.',
                ],
            ],
            'Pemahaman Bacaan dan Menulis' => [
                [
                    'question_text' => 'Kalimat manakah yang menggunakan tanda baca dengan benar?',
                    'options' => [
                        'A' => 'Hari ini, saya pergi ke sekolah.',
                        'B' => 'Hari ini saya, pergi ke sekolah.',
                        'C' => 'Hari, ini saya pergi ke sekolah.',
                        'D' => 'Hari ini saya pergi, ke sekolah.',
                        'E' => 'Hari ini saya pergi ke, sekolah.',
                    ],
                    'correct_answer' => 'A',
                    'discussion' => 'Koma digunakan setelah keterangan waktu di awal kalimat. Opsi A menggunakan tanda baca yang benar.',
                ],
                [
                    'question_text' => 'Kata "efektif" bersinonim dengan kata...',
                    'options' => [
                        'A' => 'Rumit',
                        'B' => 'Berhasil guna',
                        'C' => 'Boros',
                        'D' => 'Lambat',
                        'E' => 'Usang',
                    ],
                    'correct_answer' => 'B',
                    'discussion' => '"Efektif" bermakna tepat guna atau berhasil guna, yaitu memberikan hasil yang diinginkan.',
                ],
                [
                    'question_text' => 'Paragraf yang baik harus memiliki...',
                    'options' => [
                        'A' => 'Minimal tiga kalimat utama',
                        'B' => 'Satu gagasan pokok dan kalimat penjelas',
                        'C' => 'Dua gagasan pokok',
                        'D' => 'Tidak perlu kalimat penjelas',
                        'E' => 'Lebih dari sepuluh kalimat',
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Paragraf yang baik terdiri dari satu gagasan pokok (kalimat utama) dan beberapa kalimat penjelas yang mendukungnya.',
                ],
            ],
            'Pengetahuan Kuantitatif' => [
                [
                    'question_text' => 'Berapakah nilai dari 2³ + 3² ?',
                    'options' => [
                        'A' => '13',
                        'B' => '15',
                        'C' => '17',
                        'D' => '18',
                        'E' => '19',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => '2³ = 8 dan 3² = 9. Jadi 8 + 9 = 17.',
                ],
                [
                    'question_text' => 'Jika x + 5 = 12, maka nilai 2x adalah...',
                    'options' => [
                        'A' => '7',
                        'B' => '12',
                        'C' => '14',
                        'D' => '17',
                        'E' => '24',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'x + 5 = 12, maka x = 7. Sehingga 2x = 2 × 7 = 14.',
                ],
                [
                    'question_text' => 'Sebuah persegi panjang memiliki panjang 10 cm dan lebar 6 cm. Berapakah kelilingnya?',
                    'options' => [
                        'A' => '16 cm',
                        'B' => '30 cm',
                        'C' => '32 cm',
                        'D' => '60 cm',
                        'E' => '64 cm',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Keliling persegi panjang = 2 × (panjang + lebar) = 2 × (10 + 6) = 2 × 16 = 32 cm.',
                ],
            ],
            'Literasi dalam Bahasa Indonesia' => [
                [
                    'question_text' => 'Bacalah teks berikut: "Pemanasan global menyebabkan es di kutub mencair. Hal ini mengakibatkan kenaikan permukaan air laut." Gagasan utama teks tersebut adalah...',
                    'options' => [
                        'A' => 'Es di kutub mencair akibat pemanasan global',
                        'B' => 'Permukaan air laut naik',
                        'C' => 'Pemanasan global berdampak pada mencairnya es kutub dan naiknya permukaan laut',
                        'D' => 'Kutub adalah tempat yang berbahaya',
                        'E' => 'Perlu tindakan untuk mengatasi pemanasan global',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Gagasan utama mencakup keseluruhan isi teks: pemanasan global sebagai penyebab dan dampaknya (es mencair + naiknya permukaan laut).',
                ],
                [
                    'question_text' => 'Kalimat "Buku itu dibaca oleh Andi" termasuk kalimat...',
                    'options' => [
                        'A' => 'Aktif',
                        'B' => 'Pasif',
                        'C' => 'Majemuk',
                        'D' => 'Tunggal aktif',
                        'E' => 'Elips',
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Kalimat pasif ditandai dengan predikat yang menggunakan awalan "di-". Kalimat "dibaca oleh" adalah bentuk pasif.',
                ],
            ],
            'Literasi dalam Bahasa Inggris' => [
                [
                    'question_text' => 'Choose the correct sentence:',
                    'options' => [
                        'A' => 'She don\'t like apples.',
                        'B' => 'She doesn\'t likes apples.',
                        'C' => 'She doesn\'t like apples.',
                        'D' => 'She not like apples.',
                        'E' => 'She do not likes apples.',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'For third person singular (she/he/it) in simple present tense, we use "doesn\'t" + base verb. "doesn\'t like" is correct.',
                ],
                [
                    'question_text' => 'What is the synonym of "happy"?',
                    'options' => [
                        'A' => 'Sad',
                        'B' => 'Angry',
                        'C' => 'Joyful',
                        'D' => 'Tired',
                        'E' => 'Bored',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => '"Joyful" means feeling great happiness, which is a synonym of "happy".',
                ],
            ],
            'Penalaran Matematika' => [
                [
                    'question_text' => 'Rata-rata nilai ujian 5 siswa adalah 75. Jika satu siswa mendapat nilai 85, berapakah rata-rata nilai 4 siswa lainnya?',
                    'options' => [
                        'A' => '72',
                        'B' => '72,5',
                        'C' => '73',
                        'D' => '73,5',
                        'E' => '74',
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Total nilai = 5 × 75 = 375. Nilai 4 siswa lainnya = 375 - 85 = 290. Rata-rata = 290 ÷ 4 = 72,5.',
                ],
                [
                    'question_text' => 'Sebuah toko memberikan diskon 20% untuk barang seharga Rp 150.000. Harga setelah diskon adalah...',
                    'options' => [
                        'A' => 'Rp 100.000',
                        'B' => 'Rp 110.000',
                        'C' => 'Rp 120.000',
                        'D' => 'Rp 125.000',
                        'E' => 'Rp 130.000',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Diskon 20% = 20% × 150.000 = 30.000. Harga setelah diskon = 150.000 - 30.000 = 120.000.',
                ],
                [
                    'question_text' => 'Jika luas lingkaran adalah 154 cm² (π = 22/7), maka jari-jari lingkaran adalah...',
                    'options' => [
                        'A' => '5 cm',
                        'B' => '6 cm',
                        'C' => '7 cm',
                        'D' => '8 cm',
                        'E' => '9 cm',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'L = πr². 154 = (22/7)r². r² = 154 × 7/22 = 49. r = 7 cm.',
                ],
            ],
        ];

        foreach ($sampleQuestions as $subtestName => $questions) {
            $subtest = Subtest::where('name', $subtestName)->first();
            if (!$subtest) continue;

            foreach ($questions as $index => $q) {
                $question = DB::transaction(function () use ($subtest, $q, $index) {
                    $existing = Question::where('subtest_id', $subtest->id)
                        ->where('question_text', $q['question_text'])
                        ->first();

                    if ($existing) return $existing;

                    $created = Question::create([
                        'subtest_id'     => $subtest->id,
                        'question_text'  => $q['question_text'],
                        'discussion'     => $q['discussion'],
                        'correct_answer' => $q['correct_answer'],
                        'order_no'       => $index + 1,
                        'is_active'      => true,
                    ]);

                    foreach ($q['options'] as $key => $text) {
                        QuestionOption::create([
                            'question_id' => $created->id,
                            'option_key'  => $key,
                            'option_text' => $text,
                        ]);
                    }

                    return $created;
                });
            }
        }
    }
}
