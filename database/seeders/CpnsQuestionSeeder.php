<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample SKD questions so CPNS tryouts are actually attemptable.
 * Mirrors QuestionSeeder's shape; keyed by subtest name.
 */
class CpnsQuestionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->questions() as $subtestName => $questions) {
            $subtest = Subtest::where('name', $subtestName)
                ->where('exam_type', 'cpns')
                ->first();

            if (! $subtest) {
                continue;
            }

            foreach ($questions as $index => $q) {
                DB::transaction(function () use ($subtest, $q, $index) {
                    $exists = Question::where('subtest_id', $subtest->id)
                        ->where('question_text', $q['question_text'])
                        ->exists();

                    if ($exists) {
                        return;
                    }

                    $created = Question::create([
                        'subtest_id'     => $subtest->id,
                        'question_text'  => $q['question_text'],
                        'discussion'     => $q['discussion'],
                        'correct_answer' => $q['correct_answer'],
                        'order_no'       => $index + 1,
                        'is_active'      => true,
                    ]);

                    foreach ($q['options'] as $key => $option) {
                        // TKP options carry their own 1-5 weight; the rest are
                        // plain strings scored right/wrong.
                        $isWeighted = is_array($option);
                        $text  = $isWeighted ? $option['text'] : $option;
                        $score = $isWeighted
                            ? (float) $option['score']
                            : ($key === $q['correct_answer'] ? 1.0 : 0.0);

                        QuestionOption::create([
                            'question_id' => $created->id,
                            'option_key'  => $key,
                            'option_text' => $text,
                            'score'       => $score,
                            'is_correct'  => $key === $q['correct_answer'],
                        ]);
                    }
                });
            }
        }
    }

    private function questions(): array
    {
        return [
            'Tes Wawasan Kebangsaan (TWK)' => [
                [
                    'question_text' => 'Pancasila disahkan sebagai dasar negara Indonesia pada tanggal...',
                    'options' => [
                        'A' => '1 Juni 1945',
                        'B' => '22 Juni 1945',
                        'C' => '17 Agustus 1945',
                        'D' => '18 Agustus 1945',
                        'E' => '29 Mei 1945',
                    ],
                    'correct_answer' => 'D',
                    'discussion' => 'Pancasila disahkan oleh PPKI pada 18 Agustus 1945, bersamaan dengan pengesahan UUD 1945.',
                ],
                [
                    'question_text' => 'Sila kelima Pancasila berbunyi...',
                    'options' => [
                        'A' => 'Ketuhanan Yang Maha Esa',
                        'B' => 'Kemanusiaan yang adil dan beradab',
                        'C' => 'Persatuan Indonesia',
                        'D' => 'Kerakyatan yang dipimpin oleh hikmat kebijaksanaan',
                        'E' => 'Keadilan sosial bagi seluruh rakyat Indonesia',
                    ],
                    'correct_answer' => 'E',
                    'discussion' => 'Sila kelima menekankan pemerataan kesejahteraan bagi seluruh rakyat Indonesia.',
                ],
                [
                    'question_text' => 'Lembaga negara yang berwenang menguji undang-undang terhadap UUD 1945 adalah...',
                    'options' => [
                        'A' => 'Mahkamah Agung',
                        'B' => 'Mahkamah Konstitusi',
                        'C' => 'Komisi Yudisial',
                        'D' => 'Dewan Perwakilan Rakyat',
                        'E' => 'Badan Pemeriksa Keuangan',
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Sesuai Pasal 24C UUD 1945, Mahkamah Konstitusi berwenang menguji UU terhadap UUD.',
                ],
                [
                    'question_text' => 'Semboyan Bhinneka Tunggal Ika berasal dari kitab...',
                    'options' => [
                        'A' => 'Negarakertagama',
                        'B' => 'Pararaton',
                        'C' => 'Sutasoma',
                        'D' => 'Arjunawiwaha',
                        'E' => 'Smaradahana',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Semboyan ini terdapat dalam Kakawin Sutasoma karya Mpu Tantular pada masa Majapahit.',
                ],
                [
                    'question_text' => 'UUD 1945 telah mengalami amandemen sebanyak ... kali.',
                    'options' => [
                        'A' => 'Dua',
                        'B' => 'Tiga',
                        'C' => 'Empat',
                        'D' => 'Lima',
                        'E' => 'Enam',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Amandemen dilakukan empat kali: tahun 1999, 2000, 2001, dan 2002.',
                ],
            ],

            'Tes Intelegensi Umum (TIU)' => [
                [
                    'question_text' => 'Sinonim dari kata PROMINEN adalah...',
                    'options' => [
                        'A' => 'Terkemuka',
                        'B' => 'Tersembunyi',
                        'C' => 'Sederhana',
                        'D' => 'Terlambat',
                        'E' => 'Tergesa-gesa',
                    ],
                    'correct_answer' => 'A',
                    'discussion' => 'Prominen berarti terkemuka, menonjol, atau ternama.',
                ],
                [
                    'question_text' => 'Deret angka 3, 6, 12, 24, ... Angka berikutnya adalah...',
                    'options' => [
                        'A' => '30',
                        'B' => '36',
                        'C' => '42',
                        'D' => '48',
                        'E' => '54',
                    ],
                    'correct_answer' => 'D',
                    'discussion' => 'Setiap suku dikalikan 2, sehingga suku berikutnya adalah 24 x 2 = 48.',
                ],
                [
                    'question_text' => 'Jika 2x + 5 = 17, maka nilai x adalah...',
                    'options' => [
                        'A' => '4',
                        'B' => '5',
                        'C' => '6',
                        'D' => '7',
                        'E' => '8',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => '2x = 17 - 5 = 12, sehingga x = 6.',
                ],
                [
                    'question_text' => 'Semua pegawai wajib mengikuti apel pagi. Budi adalah pegawai. Kesimpulannya...',
                    'options' => [
                        'A' => 'Budi tidak wajib mengikuti apel',
                        'B' => 'Budi wajib mengikuti apel pagi',
                        'C' => 'Sebagian pegawai tidak apel',
                        'D' => 'Budi bukan pegawai',
                        'E' => 'Apel pagi bersifat sukarela',
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Silogisme: semua P wajib Q, Budi termasuk P, maka Budi wajib Q.',
                ],
                [
                    'question_text' => 'Sebuah pekerjaan selesai dalam 12 hari oleh 5 orang. Jika dikerjakan 15 orang, berapa hari selesai?',
                    'options' => [
                        'A' => '2 hari',
                        'B' => '3 hari',
                        'C' => '4 hari',
                        'D' => '5 hari',
                        'E' => '6 hari',
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Perbandingan berbalik nilai: 5 x 12 = 15 x n, maka n = 60 / 15 = 4 hari.',
                ],
            ],

            'Tes Karakteristik Pribadi (TKP)' => [
                [
                    'question_text' => 'Anda menerima tugas mendadak menjelang jam pulang. Sikap Anda...',
                    'options' => [
                        'A' => ['text' => 'Menolak karena sudah waktunya pulang', 'score' => 1],
                        'B' => ['text' => 'Menunda ke esok hari tanpa memberi tahu atasan', 'score' => 2],
                        'C' => ['text' => 'Mengerjakan seadanya lalu segera pulang', 'score' => 3],
                        'D' => ['text' => 'Menyelesaikan tugas tersebut sebaik mungkin', 'score' => 5],
                        'E' => ['text' => 'Meminta rekan lain yang mengerjakan', 'score' => 4],
                    ],
                    'correct_answer' => 'D',
                    'discussion' => 'Aspek pelayanan publik: menuntaskan tanggung jawab adalah sikap yang paling tepat.',
                ],
                [
                    'question_text' => 'Rekan kerja Anda melakukan kesalahan yang merugikan tim. Anda sebaiknya...',
                    'options' => [
                        'A' => ['text' => 'Melaporkannya ke seluruh kantor', 'score' => 2],
                        'B' => ['text' => 'Menegur secara pribadi dan membantu memperbaiki', 'score' => 5],
                        'C' => ['text' => 'Mendiamkan agar tidak terjadi konflik', 'score' => 3],
                        'D' => ['text' => 'Menjauhi rekan tersebut', 'score' => 4],
                        'E' => ['text' => 'Menyalahkannya di depan atasan', 'score' => 1],
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Aspek jejaring kerja: menegur secara personal sambil menawarkan solusi menjaga hubungan dan kinerja.',
                ],
                [
                    'question_text' => 'Kantor Anda menerapkan sistem kerja baru yang belum Anda kuasai. Anda akan...',
                    'options' => [
                        'A' => ['text' => 'Menunggu sampai diajari atasan', 'score' => 3],
                        'B' => ['text' => 'Tetap memakai cara lama yang sudah dikuasai', 'score' => 2],
                        'C' => ['text' => 'Mempelajarinya secara mandiri dan bertanya bila perlu', 'score' => 5],
                        'D' => ['text' => 'Meminta dipindahkan ke bagian lain', 'score' => 1],
                        'E' => ['text' => 'Mengeluh kepada rekan kerja', 'score' => 4],
                    ],
                    'correct_answer' => 'C',
                    'discussion' => 'Aspek kemampuan beradaptasi: inisiatif belajar mandiri bernilai paling tinggi.',
                ],
                [
                    'question_text' => 'Anda menemukan pelanggaran prosedur yang dilakukan atasan. Sikap Anda...',
                    'options' => [
                        'A' => ['text' => 'Diam saja karena beliau atasan', 'score' => 3],
                        'B' => ['text' => 'Menyebarkannya ke media sosial', 'score' => 1],
                        'C' => ['text' => 'Ikut melakukan hal serupa', 'score' => 2],
                        'D' => ['text' => 'Menyampaikan keberatan melalui saluran resmi', 'score' => 5],
                        'E' => ['text' => 'Membicarakannya dengan rekan lain', 'score' => 4],
                    ],
                    'correct_answer' => 'D',
                    'discussion' => 'Aspek integritas: pelanggaran disampaikan lewat mekanisme resmi, bukan didiamkan atau disebarkan.',
                ],
                [
                    'question_text' => 'Saat bekerja dalam tim dengan latar belakang beragam, Anda...',
                    'options' => [
                        'A' => ['text' => 'Memilih rekan yang selatar belakang saja', 'score' => 2],
                        'B' => ['text' => 'Menghargai perbedaan dan mencari titik temu', 'score' => 5],
                        'C' => ['text' => 'Menghindari diskusi kelompok', 'score' => 1],
                        'D' => ['text' => 'Memaksakan pendapat sendiri', 'score' => 3],
                        'E' => ['text' => 'Mengikuti mayoritas tanpa berpendapat', 'score' => 4],
                    ],
                    'correct_answer' => 'B',
                    'discussion' => 'Aspek sosial budaya: menghargai keberagaman sambil tetap produktif adalah sikap terbaik.',
                ],
            ],
        ];
    }
}
