<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserAnswer;
use App\Services\RankingService;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FullSkdPassingAndRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_skd_requires_cpns_and_the_complete_active_question_composition(): void
    {
        [$fullSkd] = $this->createSkdTryout();
        [$miniSkd] = $this->createSkdTryout(['twk' => 10, 'tiu' => 10, 'tkp' => 10]);

        $this->assertTrue(ScoringService::isFullSkd($fullSkd));
        $this->assertFalse(ScoringService::isFullSkd($miniSkd));
    }

    public function test_skd_passing_status_fails_when_one_subtest_is_below_its_threshold(): void
    {
        [$tryout, $questions] = $this->createSkdTryout();
        $student = User::factory()->create(['kategori' => 'cpns']);
        $session = $this->createFinishedSession($student, $tryout, 1);

        $this->seedScores($session, $questions, [
            'twk' => 60,
            'tiu' => 160,
            'tkp' => 200,
        ]);

        $status = ScoringService::calculateSkdPassingStatus($session);

        $this->assertFalse($status['is_passed_skd']);
        $this->assertSame(['twk' => 60.0, 'tiu' => 160.0, 'tkp' => 200.0], $status['scores']);
        $this->assertFalse($status['subtests']['twk']['is_passed']);
        $this->assertTrue($status['subtests']['tiu']['is_passed']);
        $this->assertTrue($status['subtests']['tkp']['is_passed']);
    }

    public function test_result_exposes_full_skd_verdict_scores_and_thresholds(): void
    {
        [$tryout, $questions] = $this->createSkdTryout();
        $student = User::factory()->create(['kategori' => 'cpns']);
        $session = $this->createFinishedSession($student, $tryout, 1);

        $this->seedScores($session, $questions, [
            'twk' => 65,
            'tiu' => 80,
            'tkp' => 166,
        ]);

        $response = $this->actingAs($student)->getJson("/api/tryouts/{$tryout->id}/result");

        $response
            ->assertOk()
            ->assertJsonPath('data.is_full_skd', true)
            ->assertJsonPath('data.is_passed_skd', true)
            ->assertJsonPath('data.skd_scores.twk', 65)
            ->assertJsonPath('data.skd_scores.tiu', 80)
            ->assertJsonPath('data.skd_scores.tkp', 166)
            ->assertJsonPath('data.skd_passing_grades.twk', 65)
            ->assertJsonPath('data.skd_passing_grades.tiu', 80)
            ->assertJsonPath('data.skd_passing_grades.tkp', 166);
    }

    public function test_full_skd_leaderboard_prioritizes_passing_then_total_and_tkp(): void
    {
        [$tryout, $questions] = $this->createSkdTryout();

        $passingLowerTotal = User::factory()->create(['name' => 'Lulus 360', 'kategori' => 'cpns']);
        $failingHigherTotal = User::factory()->create(['name' => 'Tidak Lulus 420', 'kategori' => 'cpns']);
        $passingHigherTkp = User::factory()->create(['name' => 'TKP 210', 'kategori' => 'cpns']);
        $passingLowerTkp = User::factory()->create(['name' => 'TKP 200', 'kategori' => 'cpns']);

        $fixtures = [
            [$passingLowerTotal, ['twk' => 65, 'tiu' => 80, 'tkp' => 215], now()->subMinutes(4)],
            [$failingHigherTotal, ['twk' => 60, 'tiu' => 165, 'tkp' => 195], now()->subMinutes(5)],
            [$passingHigherTkp, ['twk' => 70, 'tiu' => 120, 'tkp' => 210], now()->subMinutes(2)],
            [$passingLowerTkp, ['twk' => 80, 'tiu' => 120, 'tkp' => 200], now()->subMinutes(3)],
        ];

        foreach ($fixtures as [$user, $scores, $finishedAt]) {
            $session = $this->createFinishedSession($user, $tryout, 1, $finishedAt);
            $this->seedScores($session, $questions, $scores);
        }

        $response = $this->actingAs($passingLowerTotal)
            ->getJson("/api/tryouts/{$tryout->id}/leaderboard");

        $response->assertOk()->assertJsonPath('data.is_full_skd', true);

        $entries = collect($response->json('data.leaderboard'));

        $this->assertSame(
            ['TKP 210', 'TKP 200', 'Lulus 360', 'Tidak Lulus 420'],
            $entries->pluck('user_name')->all(),
        );
        $this->assertSame([true, true, true, false], $entries->pluck('is_passed')->all());
        $this->assertSame([210, 200, 215, 195], $entries->pluck('tkp_score')->all());
    }

    public function test_ranking_service_uses_the_same_full_skd_hierarchy(): void
    {
        [$tryout, $questions] = $this->createSkdTryout();
        $passed = User::factory()->create(['name' => 'Lulus 311', 'kategori' => 'cpns']);
        $failed = User::factory()->create(['name' => 'Tidak Lulus 420', 'kategori' => 'cpns']);

        $passedSession = $this->createFinishedSession($passed, $tryout, 1, now()->subMinute());
        $failedSession = $this->createFinishedSession($failed, $tryout, 1, now()->subMinutes(2));
        $this->seedScores($passedSession, $questions, ['twk' => 65, 'tiu' => 80, 'tkp' => 166]);
        $this->seedScores($failedSession, $questions, ['twk' => 60, 'tiu' => 165, 'tkp' => 195]);

        $board = RankingService::leaderboard($tryout);

        $this->assertSame(['Lulus 311', 'Tidak Lulus 420'], $board->pluck('user_name')->all());
        $this->assertSame([true, false], $board->pluck('is_passed')->all());
        $this->assertSame([166.0, 195.0], $board->pluck('tkp_score')->all());
    }

    public function test_mini_cpns_leaderboard_keeps_total_score_ordering(): void
    {
        [$tryout, $questions] = $this->createSkdTryout(['twk' => 2, 'tiu' => 2, 'tkp' => 2]);
        $lower = User::factory()->create(['name' => 'Skor Rendah', 'kategori' => 'cpns']);
        $higher = User::factory()->create(['name' => 'Skor Tinggi', 'kategori' => 'cpns']);

        $lowerSession = $this->createFinishedSession($lower, $tryout, 1, now()->subMinute());
        $higherSession = $this->createFinishedSession($higher, $tryout, 1, now()->subMinutes(2));
        $this->seedScores($lowerSession, $questions, ['twk' => 5, 'tiu' => 5, 'tkp' => 5]);
        $this->seedScores($higherSession, $questions, ['twk' => 10, 'tiu' => 10, 'tkp' => 10]);

        $response = $this->actingAs($lower)->getJson("/api/tryouts/{$tryout->id}/leaderboard");

        $response->assertOk()->assertJsonPath('data.is_full_skd', false);
        $this->assertSame(
            ['Skor Tinggi', 'Skor Rendah'],
            collect($response->json('data.leaderboard'))->pluck('user_name')->all(),
        );
        $this->assertArrayNotHasKey('is_passed', $response->json('data.leaderboard.0'));
    }

    public function test_full_skd_ties_use_tiu_then_the_earlier_finish_time(): void
    {
        $rows = collect([
            [
                'user_id' => 'tiu-tinggi-lambat',
                'is_passed' => true,
                'score' => ['final_score' => 400],
                'tkp_score' => 210,
                'tiu_score' => 125,
                'twk_score' => 65,
                'finished_at' => '2026-09-03 10:05:00',
            ],
            [
                'user_id' => 'tiu-rendah',
                'is_passed' => true,
                'score' => ['final_score' => 400],
                'tkp_score' => 210,
                'tiu_score' => 120,
                'twk_score' => 70,
                'finished_at' => '2026-09-03 09:00:00',
            ],
            [
                'user_id' => 'tiu-tinggi-cepat',
                'is_passed' => true,
                'score' => ['final_score' => 400],
                'tkp_score' => 210,
                'tiu_score' => 125,
                'twk_score' => 65,
                'finished_at' => '2026-09-03 10:00:00',
            ],
        ]);

        $ranked = RankingService::rankBestAttempts($rows, true);

        $this->assertSame(
            ['tiu-tinggi-cepat', 'tiu-tinggi-lambat', 'tiu-rendah'],
            $ranked->pluck('user_id')->all(),
        );
    }

    public function test_passing_attempt_is_the_best_attempt_even_with_a_lower_total(): void
    {
        $rows = collect([
            [
                'user_id' => 'peserta-sama',
                'attempt_number' => 1,
                'is_passed' => false,
                'score' => ['final_score' => 420],
                'tkp_score' => 195,
                'tiu_score' => 165,
                'twk_score' => 60,
                'finished_at' => '2026-09-03 09:00:00',
            ],
            [
                'user_id' => 'peserta-sama',
                'attempt_number' => 2,
                'is_passed' => true,
                'score' => ['final_score' => 360],
                'tkp_score' => 215,
                'tiu_score' => 80,
                'twk_score' => 65,
                'finished_at' => '2026-09-03 10:00:00',
            ],
        ]);

        $ranked = RankingService::rankBestAttempts($rows, true);

        $this->assertCount(1, $ranked);
        $this->assertSame(2, $ranked->first()['attempt_number']);
    }

    /**
     * @param  array{twk: int, tiu: int, tkp: int}  $questionCounts
     * @return array{Tryout, array<string, array<int, Question>>}
     */
    private function createSkdTryout(array $questionCounts = ['twk' => 30, 'tiu' => 35, 'tkp' => 45]): array
    {
        $creator = User::factory()->create(['role' => 'admin']);
        $tryout = Tryout::create([
            'title' => 'Tryout SKD ' . fake()->unique()->numerify('###'),
            'category' => 'CPNS',
            'kategori' => 'cpns',
            'use_irt' => false,
            'is_published' => true,
            'created_by' => $creator->id,
        ]);
        $questions = [];

        foreach ($questionCounts as $code => $count) {
            $isTkp = $code === 'tkp';
            $subtest = Subtest::create([
                'name' => strtoupper($code) . ' ' . substr($tryout->id, -6),
                'category' => strtoupper($code),
                'exam_type' => 'cpns',
                'max_questions' => $count,
                'scoring_scheme' => $isTkp
                    ? ScoringService::SCHEME_OPTION_WEIGHT
                    : ScoringService::SCHEME_RIGHT_WRONG,
                'score_correct' => 5,
                'score_wrong' => 0,
                'score_empty' => 0,
            ]);

            TryoutSubtest::create([
                'tryout_id' => $tryout->id,
                'subtest_id' => $subtest->id,
                'duration_minutes' => 30,
                'is_active' => true,
            ]);

            for ($number = 1; $number <= $count; $number++) {
                $questions[$code][] = Question::create([
                    'subtest_id' => $subtest->id,
                    'question_type' => 'multiple_choice',
                    'question_text' => strtoupper($code) . " {$number}",
                    'correct_answer' => 'A',
                    'order_no' => $number,
                    'is_active' => true,
                ]);
            }
        }

        return [$tryout, $questions];
    }

    private function createFinishedSession(
        User $user,
        Tryout $tryout,
        int $attempt,
        $finishedAt = null,
    ): TryoutSession {
        return TryoutSession::create([
            'user_id' => $user->id,
            'tryout_id' => $tryout->id,
            'attempt_number' => $attempt,
            'status' => 'finished',
            'started_at' => now()->subHour(),
            'finished_at' => $finishedAt ?? now(),
        ]);
    }

    /** @param array<string, array<int, Question>> $questions */
    private function seedScores(TryoutSession $session, array $questions, array $scores): void
    {
        foreach ($scores as $code => $score) {
            $remaining = $score;

            foreach ($questions[$code] as $question) {
                if ($remaining <= 0) {
                    break;
                }

                $answerScore = min(5, $remaining);
                UserAnswer::create([
                    'tryout_session_id' => $session->id,
                    'question_id' => $question->id,
                    'answer' => 'A',
                    'is_correct' => $answerScore === 5,
                    'score' => $answerScore,
                ]);
                $remaining -= $answerScore;
            }

            $this->assertSame(0, $remaining, "Skor {$code} melebihi kapasitas soal fixture.");
        }

        $session->update([
            'total_score' => array_sum($scores),
            'raw_score' => array_sum($scores),
            'scoring_method' => 'simple',
            'score_finalized' => true,
        ]);
    }
}
