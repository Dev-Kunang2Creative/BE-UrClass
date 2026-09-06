<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserAnswer;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleDummyDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_hapus_dummy_menghitung_ulang_cache_bobot_yang_jumlah_pesertanya_pernah_sama(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tryout = Tryout::create(['title' => 'IRT', 'created_by' => $admin->id, 'use_irt' => true]);
        $subtest = Subtest::create(['name' => 'TPS', 'category' => 'TPS', 'exam_type' => 'utbk']);
        TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id, 'duration_minutes' => 10, 'is_active' => true]);
        $question = Question::create(['subtest_id' => $subtest->id, 'question_type' => 'multiple_choice', 'question_text' => 'Soal', 'is_active' => true]);
        $dummy = User::factory()->create(['is_dummy' => true]);
        $session = TryoutSession::create(['tryout_id' => $tryout->id, 'user_id' => $dummy->id, 'status' => 'finished', 'attempt_number' => 1]);
        UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $question->id, 'answer' => 'A', 'is_correct' => true]);
        $this->assertEquals(1, ScoringService::irtWeights($tryout, [$subtest->id])['weights'][$question->id]);
        TryoutSession::create(['tryout_id' => $tryout->id, 'user_id' => $admin->id, 'status' => 'finished', 'attempt_number' => 1]);
        $this->actingAs($admin)->deleteJson("/api/admin/tryouts/{$tryout->id}/leaderboard/dummy/{$dummy->id}")->assertOk();
        $this->assertGreaterThan(1, ScoringService::irtWeights($tryout, [$subtest->id])['weights'][$question->id]);
    }

    public function test_admin_menghapus_satu_dummy_beserta_jawaban_dan_mencatat_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tryout = Tryout::create(['title' => 'Latihan', 'created_by' => $admin->id]);
        $dummy = User::factory()->create(['is_dummy' => true]);
        $lain = User::factory()->create(['is_dummy' => true]);
        $session = TryoutSession::create(['tryout_id' => $tryout->id, 'user_id' => $dummy->id,
            'attempt_number' => 1, 'status' => 'finished']);
        $subtest = Subtest::create(['name' => 'TWK', 'category' => 'TWK', 'exam_type' => 'cpns']);
        $question = Question::create(['subtest_id' => $subtest->id, 'question_type' => 'multiple_choice', 'question_text' => 'Soal']);
        $answer = UserAnswer::create(['tryout_session_id' => $session->id, 'question_id' => $question->id, 'answer' => 'A']);
        $url = "/api/admin/tryouts/{$tryout->id}/leaderboard/dummy/{$dummy->id}";
        $this->deleteJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'user']))->deleteJson($url)->assertForbidden();
        $this->actingAs($admin)->deleteJson($url)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $dummy->id]);
        $this->assertDatabaseMissing('tryout_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('user_answers', ['id' => $answer->id]);
        $this->assertDatabaseHas('users', ['id' => $lain->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'TryoutDummyParticipant', 'action' => 'delete_single', 'subject_id' => $tryout->id]);
    }

    public function test_menolak_peserta_asli_dan_dummy_yang_bukan_peserta_tryout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tryout = Tryout::create(['title' => 'Latihan', 'created_by' => $admin->id]);
        $asli = User::factory()->create(['is_dummy' => false]);
        $dummy = User::factory()->create(['is_dummy' => true]);
        $this->actingAs($admin)->deleteJson("/api/admin/tryouts/{$tryout->id}/leaderboard/dummy/{$asli->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'Hanya peserta dummy yang dapat dihapus dari leaderboard');
        $this->deleteJson("/api/admin/tryouts/{$tryout->id}/leaderboard/dummy/{$dummy->id}")->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $asli->id]);
        $this->assertDatabaseHas('users', ['id' => $dummy->id]);
    }
}
