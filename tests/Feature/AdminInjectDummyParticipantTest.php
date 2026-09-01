<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserAnswer;
use App\Models\UserTryoutAccess;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminInjectDummyParticipantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private User $referenceStudent;

    private Tryout $tryout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin-dummy@urclass.test',
        ]);

        $this->student = User::factory()->create([
            'role' => 'user',
            'email' => 'student-dummy@urclass.test',
        ]);

        $this->referenceStudent = User::factory()->create([
            'role' => 'user',
            'email' => 'reference-school@urclass.test',
            'school_id' => 'REF-SMAN-1',
            'school_name' => 'SMAN 1 Bandung',
            'school_origin' => 'SMAN 1 Bandung',
            'region_province' => 'Jawa Barat',
            'region_city' => 'Kota Bandung',
            'province' => 'Jawa Barat',
            'city' => 'Kota Bandung',
        ]);

        $this->tryout = $this->makeTryoutWithQuestions();
    }

    public function test_admin_can_inject_random_dummy_participants_with_native_answers_and_leaderboard_scores(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            "/api/admin/tryouts/{$this->tryout->id}/inject-dummy-random",
            ['count' => 5, 'score_preset' => 'normal'],
        );

        $response->assertOk()
            ->assertJsonPath('count', 5);

        $dummyUsers = User::dummy()->get();
        $this->assertCount(5, $dummyUsers);
        $this->assertTrue($dummyUsers->every(fn (User $user) => $user->school_name === 'SMAN 1 Bandung'
            && $user->region_province === 'Jawa Barat'
            && $user->region_city === 'Kota Bandung'
        ));

        $sessions = TryoutSession::where('tryout_id', $this->tryout->id)
            ->whereIn('user_id', $dummyUsers->pluck('id'))
            ->get();

        $this->assertCount(5, $sessions);
        $this->assertTrue($sessions->every(fn (TryoutSession $session) => $session->status === 'finished'
            && $session->attempt_number === 1
            && $session->started_at !== null
            && $session->finished_at !== null
        ));
        $this->assertDatabaseCount('user_answers', 60);

        $leaderboard = $this->actingAs($this->student)
            ->getJson("/api/tryouts/{$this->tryout->id}/leaderboard");

        $leaderboard->assertOk()
            ->assertJsonPath('data.total_participants', 5);

        $rows = collect($leaderboard->json('data.leaderboard'));
        $this->assertCount(5, $rows);
        $this->assertTrue($rows->every(fn (array $row) => $row['score']['final_score'] >= 0
            && $row['score']['final_score'] <= 1000
            && $row['summary']['answered'] === 12
        ));
    }

    public function test_student_cannot_access_dummy_participant_admin_endpoints(): void
    {
        $this->actingAs($this->student)
            ->postJson(
                "/api/admin/tryouts/{$this->tryout->id}/inject-dummy-random",
                ['count' => 2, 'score_preset' => 'normal'],
            )
            ->assertForbidden();

        $this->actingAs($this->student)
            ->deleteJson("/api/admin/tryouts/{$this->tryout->id}/clear-dummy")
            ->assertForbidden();
    }

    public function test_admin_can_clear_dummy_participants_without_deleting_real_participants(): void
    {
        UserTryoutAccess::create([
            'user_id' => $this->student->id,
            'tryout_id' => $this->tryout->id,
            'granted_at' => now(),
        ]);

        $realSession = TryoutSession::create([
            'user_id' => $this->student->id,
            'tryout_id' => $this->tryout->id,
            'attempt_number' => 1,
            'status' => 'finished',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);

        $question = Question::query()->firstOrFail();
        UserAnswer::create([
            'tryout_session_id' => $realSession->id,
            'question_id' => $question->id,
            'answer' => 'A',
            'is_correct' => true,
            'score' => 1,
        ]);

        $this->actingAs($this->admin)->postJson(
            "/api/admin/tryouts/{$this->tryout->id}/inject-dummy-random",
            ['count' => 3, 'score_preset' => 'competitive'],
        )->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/tryouts/{$this->tryout->id}/clear-dummy")
            ->assertOk()
            ->assertJsonPath('count', 3);

        $this->assertSame(0, User::dummy()->count());
        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'is_dummy' => false]);
        $this->assertDatabaseHas('user_tryout_access', [
            'user_id' => $this->student->id,
            'tryout_id' => $this->tryout->id,
        ]);
        $this->assertDatabaseHas('tryout_sessions', ['id' => $realSession->id]);
        $this->assertDatabaseHas('user_answers', [
            'tryout_session_id' => $realSession->id,
            'question_id' => $question->id,
        ]);
    }

    public function test_admin_user_list_excludes_dummy_accounts_by_default(): void
    {
        User::factory()->create([
            'role' => 'user',
            'email' => 'hidden-dummy@dummy.urclass.id',
            'is_dummy' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users?per_page=100');

        $response->assertOk();
        $users = collect($response->json('data'));

        $this->assertFalse($users->contains('email', 'hidden-dummy@dummy.urclass.id'));
        $this->assertTrue($users->every(fn (array $user) => $user['is_dummy'] === false));
    }

    public function test_admin_can_import_csv_and_read_dummy_summary(): void
    {
        $csv = implode("\n", [
            'name,school_name,region_province,region_city,score_percentage',
            'Alya Putri,SMAN 3 Semarang,Jawa Tengah,Kota Semarang,82',
            'Rizky Maulana,SMAN 5 Makassar,Sulawesi Selatan,Kota Makassar,67',
        ]);

        $response = $this->actingAs($this->admin)->post(
            "/api/admin/tryouts/{$this->tryout->id}/inject-dummy-excel",
            ['file' => UploadedFile::fake()->createWithContent('peserta.csv', $csv)],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()->assertJsonPath('count', 2);

        $this->actingAs($this->admin)
            ->getJson("/api/admin/tryouts/{$this->tryout->id}/dummy-summary")
            ->assertOk()
            ->assertJsonPath('dummy_participants', 2)
            ->assertJsonPath('real_participants', 0)
            ->assertJsonPath('total_participants', 2);
    }

    public function test_admin_can_download_dummy_excel_template(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/api/admin/tryouts/dummy-excel-template');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'template-peserta-dummy.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
    }

    private function makeTryoutWithQuestions(): Tryout
    {
        $tryout = Tryout::create([
            'title' => 'Simulasi UTBK Dummy',
            'category' => 'UTBK',
            'kategori' => 'utbk',
            'use_irt' => true,
            'is_published' => true,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDay(),
            'created_by' => $this->admin->id,
        ]);

        $subtest = Subtest::create([
            'name' => 'Penalaran Umum',
            'category' => 'TPS',
            'exam_type' => 'utbk',
            'max_questions' => 12,
            'scoring_scheme' => ScoringService::SCHEME_IRT,
        ]);

        TryoutSubtest::create([
            'tryout_id' => $tryout->id,
            'subtest_id' => $subtest->id,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        foreach (range(1, 12) as $number) {
            $question = Question::create([
                'subtest_id' => $subtest->id,
                'question_type' => 'multiple_choice',
                'question_text' => "Soal {$number}",
                'correct_answer' => 'A',
                'order_no' => $number,
                'is_active' => true,
            ]);

            foreach (['A', 'B', 'C', 'D', 'E'] as $optionKey) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key' => $optionKey,
                    'option_text' => "Opsi {$optionKey}",
                    'score' => $optionKey === 'A' ? 1 : 0,
                    'is_correct' => $optionKey === 'A',
                ]);
            }
        }

        return $tryout;
    }
}
