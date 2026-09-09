<?php

namespace Tests\Feature;

use App\Models\Subtest;
use App\Models\SubtestCategory;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\UserAnswer;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtestCategoryAndScoringTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@urclass.test',
        ]);

        $this->student = User::factory()->create([
            'role' => 'user',
            'email' => 'siswa@urclass.test',
            'kategori' => 'cpns',
        ]);

        // Seed default categories
        SubtestCategory::create(['code' => 'TPS', 'name' => 'TPS', 'exam_type' => 'utbk', 'is_active' => true]);
        SubtestCategory::create(['code' => 'Literasi', 'name' => 'Literasi', 'exam_type' => 'utbk', 'is_active' => true]);
        SubtestCategory::create(['code' => 'TWK', 'name' => 'TWK', 'exam_type' => 'cpns', 'is_active' => true]);
        SubtestCategory::create(['code' => 'TIU', 'name' => 'TIU', 'exam_type' => 'cpns', 'is_active' => true]);
        SubtestCategory::create(['code' => 'TKP', 'name' => 'TKP', 'exam_type' => 'cpns', 'is_active' => true]);
    }

    public function test_get_categories_utbk_only_returns_active_utbk_categories(): void
    {
        SubtestCategory::create(['code' => 'INACTIVE_UTBK', 'name' => 'Inactive', 'exam_type' => 'utbk', 'is_active' => false]);

        $response = $this->actingAs($this->student)
            ->getJson('/api/subtest-categories?exam_type=utbk');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $codes = collect($data)->pluck('code')->all();
        $this->assertContains('TPS', $codes);
        $this->assertContains('Literasi', $codes);
        $this->assertNotContains('INACTIVE_UTBK', $codes);
        $this->assertNotContains('TWK', $codes);
    }

    public function test_get_categories_cpns_only_returns_active_cpns_categories(): void
    {
        $response = $this->actingAs($this->student)
            ->getJson('/api/subtest-categories?exam_type=cpns');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(3, $data);
        $codes = collect($data)->pluck('code')->all();
        $this->assertContains('TWK', $codes);
        $this->assertContains('TIU', $codes);
        $this->assertContains('TKP', $codes);
        $this->assertNotContains('TPS', $codes);
    }

    public function test_admin_can_create_subtest_cpns_with_twk_category(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/subtests', [
                'name' => 'Tes Wawasan Kebangsaan 1',
                'category' => 'TWK',
                'exam_type' => 'cpns',
                'max_questions' => 30,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('subtests', [
            'name' => 'Tes Wawasan Kebangsaan 1',
            'category' => 'TWK',
            'exam_type' => 'cpns',
        ]);
    }

    public function test_admin_cannot_create_subtest_cpns_with_tps_category(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/subtests', [
                'name' => 'Tes CPNS Salah Kategori',
                'category' => 'TPS',
                'exam_type' => 'cpns',
                'max_questions' => 30,
            ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_create_subtest_utbk_with_literasi(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/subtests', [
                'name' => 'Literasi Bahasa Indonesia 1',
                'category' => 'Literasi',
                'exam_type' => 'utbk',
                'max_questions' => 20,
            ]);

        $response->assertStatus(201);
    }

    public function test_admin_cannot_create_subtest_utbk_with_tkp(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/subtests', [
                'name' => 'UTBK dengan TKP',
                'category' => 'TKP',
                'exam_type' => 'utbk',
                'max_questions' => 20,
            ]);

        $response->assertStatus(422);
    }

    public function test_admin_cannot_create_subtest_with_inactive_category(): void
    {
        $inactive = SubtestCategory::create([
            'code' => 'OLD_CAT',
            'name' => 'Kategori Lama',
            'exam_type' => 'cpns',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/subtests', [
                'name' => 'Subtes Kategori Nonaktif',
                'category' => 'OLD_CAT',
                'exam_type' => 'cpns',
                'max_questions' => 20,
            ]);

        $response->assertStatus(422);
    }

    public function test_cpns_result_returns_actual_score_and_dynamic_max_score(): void
    {
        $tryout = Tryout::create([
            'title' => 'Tryout SKD CPNS 2026',
            'category' => 'CPNS',
            'kategori' => 'cpns',
            'use_irt' => false,
            'is_published' => true,
            'created_by' => $this->admin->id,
        ]);

        // 1 TWK subtest (2 questions x 5 = max 10)
        $twk = Subtest::create([
            'name' => 'TWK',
            'category' => 'TWK',
            'exam_type' => 'cpns',
            'max_questions' => 2,
            'scoring_scheme' => ScoringService::SCHEME_RIGHT_WRONG,
            'score_correct' => 5,
            'score_wrong' => 0,
            'score_empty' => 0,
        ]);

        // 1 TKP subtest (2 questions, options 1-5 = max 10)
        $tkp = Subtest::create([
            'name' => 'TKP',
            'category' => 'TKP',
            'exam_type' => 'cpns',
            'max_questions' => 2,
            'scoring_scheme' => ScoringService::SCHEME_OPTION_WEIGHT,
        ]);

        TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $twk->id, 'duration_minutes' => 30]);
        TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $tkp->id, 'duration_minutes' => 30]);

        $q1 = Question::create(['subtest_id' => $twk->id, 'question_type' => 'multiple_choice', 'question_text' => 'Q1', 'correct_answer' => 'A', 'is_active' => true]);
        $q2 = Question::create(['subtest_id' => $twk->id, 'question_type' => 'multiple_choice', 'question_text' => 'Q2', 'correct_answer' => 'B', 'is_active' => true]);

        $q3 = Question::create(['subtest_id' => $tkp->id, 'question_type' => 'multiple_choice', 'question_text' => 'Q3', 'is_active' => true]);
        QuestionOption::create(['question_id' => $q3->id, 'option_key' => 'A', 'option_text' => 'Opt A', 'score' => 5]);
        QuestionOption::create(['question_id' => $q3->id, 'option_key' => 'B', 'option_text' => 'Opt B', 'score' => 4]);

        $q4 = Question::create(['subtest_id' => $tkp->id, 'question_type' => 'multiple_choice', 'question_text' => 'Q4', 'is_active' => true]);
        QuestionOption::create(['question_id' => $q4->id, 'option_key' => 'A', 'option_text' => 'Opt A', 'score' => 5]);
        QuestionOption::create(['question_id' => $q4->id, 'option_key' => 'B', 'option_text' => 'Opt B', 'score' => 3]);

        $session = TryoutSession::create([
            'user_id' => $this->student->id,
            'tryout_id' => $tryout->id,
            'attempt_number' => 1,
            'status' => 'finished',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);

        // Student answers: Q1 correct (+5), Q2 wrong (+0), Q3 option A (+5), Q4 option B (+3) -> total 13
        UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $q1->id, 'answer' => 'A', 'is_correct' => true, 'score' => 5]);
        UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $q2->id, 'answer' => 'C', 'is_correct' => false, 'score' => 0]);
        UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $q3->id, 'answer' => 'A', 'is_correct' => false, 'score' => 5]);
        UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $q4->id, 'answer' => 'B', 'is_correct' => false, 'score' => 3]);

        $response = $this->actingAs($this->student)
            ->getJson("/api/tryouts/{$tryout->id}/result");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Total raw score = 13, max score = 20
        $this->assertEquals(13, $data['score_result']['final_score']);
        $this->assertEquals(20, $data['score_result']['max_score']);
        $this->assertLessThanOrEqual($data['score_result']['max_score'], $data['score_result']['final_score']);

        // Check per subtest
        $perSubtest = collect($data['per_subtest'])->keyBy('name');
        $this->assertEquals(5, $perSubtest['TWK']['raw_score']);
        $this->assertEquals(10, $perSubtest['TWK']['max_score']);
        $this->assertEquals(8, $perSubtest['TKP']['raw_score']);
        $this->assertEquals(10, $perSubtest['TKP']['max_score']);
    }

    public function test_admin_can_crud_subtest_categories(): void
    {
        // 1. Admin creates a category
        $createRes = $this->actingAs($this->admin)->postJson('/api/admin/subtest-categories', [
            'code' => 'SKB_IT',
            'name' => 'SKB Pranata Komputer',
            'exam_type' => 'cpns',
            'is_active' => true,
        ]);
        $createRes->assertStatus(201);
        $catId = $createRes->json('data.id');

        // 2. Admin lists categories
        $listRes = $this->actingAs($this->admin)->getJson('/api/admin/subtest-categories');
        $listRes->assertStatus(200);
        $this->assertNotEmpty(collect($listRes->json('data'))->firstWhere('code', 'SKB_IT'));

        // 3. Admin updates category
        $updateRes = $this->actingAs($this->admin)->putJson("/api/admin/subtest-categories/{$catId}", [
            'code' => 'SKB_IT',
            'name' => 'SKB Bidang TI',
            'exam_type' => 'cpns',
            'is_active' => true,
        ]);
        $updateRes->assertStatus(200);
        $this->assertEquals('SKB Bidang TI', $updateRes->json('data.name'));

        // 4. Admin toggles active
        $toggleRes = $this->actingAs($this->admin)->patchJson("/api/admin/subtest-categories/{$catId}/toggle-active");
        $toggleRes->assertStatus(200);
        $this->assertFalse($toggleRes->json('data.is_active'));

        // 5. Admin deletes category
        $delRes = $this->actingAs($this->admin)->deleteJson("/api/admin/subtest-categories/{$catId}");
        $delRes->assertStatus(200);
        $this->assertDatabaseMissing('subtest_categories', ['id' => $catId]);
    }

    public function test_student_cannot_access_admin_subtest_category_endpoints(): void
    {
        $res = $this->actingAs($this->student)->postJson('/api/admin/subtest-categories', [
            'code' => 'HACK',
            'name' => 'Hacked',
            'exam_type' => 'cpns',
        ]);
        $res->assertStatus(403);
    }

    public function test_utbk_scoring_remains_irt_scale_1000(): void
    {
        $tryout = Tryout::create([
            'title' => 'TO UTBK SNBT 2026',
            'category' => 'UTBK',
            'kategori' => 'utbk',
            'use_irt' => true,
            'is_published' => true,
            'created_by' => $this->admin->id,
            'end_date' => now()->addDays(5),
        ]);

        $tps = Subtest::create([
            'name' => 'Penalaran Umum',
            'category' => 'TPS',
            'exam_type' => 'utbk',
            'max_questions' => 2,
            'scoring_scheme' => ScoringService::SCHEME_IRT,
        ]);

        TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $tps->id, 'duration_minutes' => 30]);

        $q1 = Question::create(['subtest_id' => $tps->id, 'question_type' => 'multiple_choice', 'question_text' => 'Q1', 'correct_answer' => 'A', 'is_active' => true]);
        $q2 = Question::create(['subtest_id' => $tps->id, 'question_type' => 'multiple_choice', 'question_text' => 'Q2', 'correct_answer' => 'B', 'is_active' => true]);

        $session = TryoutSession::create([
            'user_id' => $this->student->id,
            'tryout_id' => $tryout->id,
            'attempt_number' => 1,
            'status' => 'finished',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);

        UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $q1->id, 'answer' => 'A', 'is_correct' => true, 'score' => 1]);
        UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $q2->id, 'answer' => 'A', 'is_correct' => false, 'score' => 0]);

        $res = $this->actingAs($this->student)->getJson("/api/tryouts/{$tryout->id}/result");
        $res->assertStatus(200);
        $data = $res->json('data');

        $this->assertEquals(true, $data['use_irt']);
        $this->assertEquals(1000, $data['score_result']['max_score']);
        // Provisional score for 1 of 2 correct = 500
        $this->assertEquals(500, $data['score_result']['final_score']);
    }
}
