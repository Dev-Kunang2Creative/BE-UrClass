<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\TryoutSubtestSession;
use App\Models\User;
use App\Models\UserAnswer;
use App\Models\UserTryoutAccess;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Alat ukur, bukan test kebenaran.
 *
 * Menghitung jumlah kueri dan waktu tiap endpoint pada data yang volumenya
 * menyerupai produksi. Jumlah kueri dipilih sebagai metrik utama karena tidak
 * tergantung engine maupun mesin - kalau naik sebanding jumlah baris, itu N+1,
 * dan itu terlihat di SQLite maupun MySQL.
 *
 * Dijalankan manual: php artisan test --filter=PerformanceProfileTest
 */
class PerformanceProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected array $siswa = [];

    protected Tryout $tryout;

    protected array $tryoutSubtests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVolume();
    }

    private function seedVolume(): void
    {
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@profil.test']);

        // 60 peserta, 3 subtes x 30 soal, tiap peserta menjawab semuanya.
        // Ukurannya dipilih supaya N+1 terlihat jelas tanpa membuat seeding lama.
        $this->tryout = Tryout::create([
            'title' => 'TO Profil',
            'category' => 'UTBK',
            'kategori' => 'utbk',
            'use_irt' => true,
            'is_free' => true,
            'is_published' => true,
            'created_by' => $this->admin->id,
        ]);

        $subtests = [];
        foreach (range(1, 3) as $i) {
            $subtest = Subtest::create([
                'name' => "Subtes {$i}",
                'category' => 'TPS',
                'exam_type' => 'utbk',
                'max_questions' => 30,
                'scoring_scheme' => ScoringService::SCHEME_IRT,
                'score_correct' => 1,
                'score_wrong' => 0,
                'score_empty' => 0,
            ]);

            $tryoutSubtest = TryoutSubtest::create([
                'tryout_id' => $this->tryout->id,
                'subtest_id' => $subtest->id,
                'duration_minutes' => 30,
                'is_active' => true,
            ]);
            $this->tryoutSubtests[] = $tryoutSubtest;

            $rows = [];
            foreach (range(1, 30) as $q) {
                $question = Question::create([
                    'subtest_id' => $subtest->id,
                    'order_no' => $q,
                    'question_text' => "Soal {$i}.{$q}",
                    'question_type' => 'multiple_choice',
                    'correct_answer' => 'B',
                    'discussion' => "Pembahasan soal {$i}.{$q}",
                    'is_active' => true,
                ]);

                foreach (['A', 'B', 'C', 'D', 'E'] as $label) {
                    $rows[] = [
                        'question_id' => $question->id,
                        'option_key' => $label,
                        'option_text' => "Opsi {$label}",
                        'is_correct' => $label === 'B',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            QuestionOption::insert($rows);
            $subtests[] = $subtest;
        }

        $questionIds = Question::pluck('id', 'id')->keys()->all();

        foreach (range(1, 60) as $n) {
            $user = User::factory()->create([
                'role' => 'user',
                'email' => "siswa{$n}@profil.test",
                'kategori' => 'utbk',
            ]);
            $this->siswa[] = $user;

            UserTryoutAccess::create([
                'user_id' => $user->id,
                'tryout_id' => $this->tryout->id,
                'granted_at' => now(),
            ]);

            $session = TryoutSession::create([
                'user_id' => $user->id,
                'tryout_id' => $this->tryout->id,
                'status' => 'finished',
                'attempt_number' => 1,
                'started_at' => now()->subHour(),
                'finished_at' => now(),
            ]);

            foreach ($this->tryoutSubtests as $tryoutSubtest) {
                TryoutSubtestSession::create([
                    'tryout_session_id' => $session->id,
                    'tryout_subtest_id' => $tryoutSubtest->id,
                    'status' => 'finished',
                    'started_at' => now()->subHour(),
                    'finished_at' => now(),
                ]);
            }

            $answers = [];
            foreach ($questionIds as $qid) {
                $answers[] = [
                    'tryout_session_id' => $session->id,
                    'question_id' => $qid,
                    'answer' => $n % 3 === 0 ? 'B' : 'C',
                    'is_correct' => $n % 3 === 0,
                    'score' => $n % 3 === 0 ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            UserAnswer::insert($answers);
        }
    }

    /** @return array{queries:int, ms:float, status:int, duplicates:array} */
    private function profile(User $as, string $method, string $url, array $payload = []): array
    {
        $queries = [];
        $sqlTime = 0.0;
        DB::flushQueryLog();
        DB::listen(function ($query) use (&$queries, &$sqlTime) {
            $queries[] = $query->sql;
            $sqlTime += $query->time;
        });

        $start = microtime(true);
        $response = $this->actingAs($as)->json($method, $url, $payload);
        $ms = (microtime(true) - $start) * 1000;

        // Kueri identik yang berulang adalah tanda N+1 paling jelas.
        $counts = array_count_values($queries);
        arsort($counts);
        $duplicates = array_filter($counts, fn ($n) => $n > 2);

        return [
            'queries' => count($queries),
            'ms' => $ms,
            'sql_ms' => $sqlTime,
            'status' => $response->getStatusCode(),
            'duplicates' => array_slice($duplicates, 0, 3, true),
            'error' => $response->getStatusCode() >= 400
                ? substr((string) ($response->json('message') ?? $response->getContent()), 0, 160)
                : null,
        ];
    }

    public function test_profil_endpoint_utama(): void
    {
        $siswa = $this->siswa[0];
        $id = $this->tryout->id;

        $targets = [
            ['peserta', $siswa, 'GET', '/api/auth/me'],
            ['peserta', $siswa, 'GET', '/api/tryouts'],
            ['peserta', $siswa, 'GET', '/api/my-tryouts'],
            ['peserta', $siswa, 'GET', "/api/tryouts/{$id}"],
            ['peserta', $siswa, 'GET', "/api/tryouts/{$id}/leaderboard"],
            ['peserta', $siswa, 'GET', "/api/tryouts/{$id}/result"],
            ['peserta', $siswa, 'GET', "/api/tryouts/{$id}/review"],
            ['peserta', $siswa, 'GET', '/api/subtests'],
            ['peserta', $siswa, 'GET', '/api/perguruan-tinggi?search=universitas'],
            ['peserta', $siswa, 'GET', '/api/program-studi?search=teknik'],
            ['peserta', $siswa, 'GET', '/api/instansi?search=pemerintah'],
            ['peserta', $siswa, 'GET', '/api/proof-requirements'],
            ['peserta', $siswa, 'GET', '/api/ticket-logs'],
            ['peserta', $siswa, 'GET', '/api/my-orders'],
            ['admin', $this->admin, 'GET', '/api/admin/stats'],
            ['admin', $this->admin, 'GET', '/api/admin/users'],
            ['admin', $this->admin, 'GET', '/api/admin/tryouts'],
            ['admin', $this->admin, 'GET', "/api/admin/tryouts/{$id}"],
            ['admin', $this->admin, 'GET', "/api/admin/tryouts/{$id}/participants"],
            ['admin', $this->admin, 'GET', "/api/admin/tryouts/{$id}/results"],
            ['admin', $this->admin, 'GET', '/api/admin/sales-report'],
            ['admin', $this->admin, 'GET', '/api/admin/audit-logs'],
            ['admin', $this->admin, 'GET', '/api/admin/tryout-proof-images'],
            ['admin', $this->admin, 'GET', '/api/admin/instansi'],
        ];

        $rows = [];
        foreach ($targets as [$role, $user, $method, $url]) {
            $rows[] = [$role, $method.' '.$url] + $this->profile($user, $method, $url);
        }

        fwrite(STDERR, "\n".str_repeat('=', 100)."\n");
        fwrite(STDERR, sprintf("%-6s %-46s %6s %8s %8s %8s %6s\n", 'ROLE', 'ENDPOINT', 'KUERI', 'TOTAL', 'SQL', 'PHP', 'STAT'));
        fwrite(STDERR, str_repeat('-', 100)."\n");

        usort($rows, fn ($a, $b) => $b['ms'] <=> $a['ms']);

        foreach ($rows as $row) {
            fwrite(STDERR, sprintf(
                "%-6s %-46s %6d %8.1f %8.1f %8.1f %6d\n",
                $row[0], substr($row[1], 0, 46), $row['queries'],
                $row['ms'], $row['sql_ms'], max(0, $row['ms'] - $row['sql_ms']), $row['status'],
            ));

            if ($row['error']) {
                fwrite(STDERR, sprintf("       GAGAL: %s\n", $row['error']));
            }

            foreach ($row['duplicates'] as $sql => $n) {
                fwrite(STDERR, sprintf("       %sx %s\n", $n, substr(preg_replace('/\s+/', ' ', $sql), 0, 88)));
            }
        }
        fwrite(STDERR, str_repeat('=', 100)."\n");

        $this->assertTrue(true);
    }
}
