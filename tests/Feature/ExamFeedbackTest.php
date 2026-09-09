<?php

namespace Tests\Feature;

use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function persiapan(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $peserta = User::factory()->create(['role' => 'user']);
        $tryout = Tryout::create(['title' => 'Latihan SKD', 'created_by' => $admin->id]);
        TryoutSession::create([
            'user_id' => $peserta->id, 'tryout_id' => $tryout->id, 'attempt_number' => 1,
            'status' => 'finished', 'started_at' => now()->subHour(), 'finished_at' => now(),
        ]);

        return [$admin, $peserta, $tryout];
    }

    private function masukan(): array
    {
        return ['rating' => 4, 'category' => 'kualitas_soal', 'comment' => 'Tambahkan variasi soal.'];
    }

    public function test_peserta_selesai_mengirim_masukan_dan_pengiriman_ulang_tidak_menggandakan(): void
    {
        [, $peserta, $tryout] = $this->persiapan();
        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/feedback", $this->masukan())
            ->assertCreated()->assertJsonPath('data.user_id', $peserta->id);
        $this->postJson("/api/tryouts/{$tryout->id}/feedback", $this->masukan())->assertSuccessful();
        $this->assertDatabaseCount('exam_feedbacks', 1);
        $this->assertDatabaseHas('exam_feedbacks', ['tryout_id' => $tryout->id, 'rating' => 4]);
    }

    public function test_masukan_memerlukan_autentikasi_dan_sesi_selesai_milik_pengirim(): void
    {
        [, , $tryout] = $this->persiapan();
        $url = "/api/tryouts/{$tryout->id}/feedback";
        $this->postJson($url, $this->masukan())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->postJson($url, $this->masukan())->assertForbidden();
    }

    public function test_rating_kategori_dan_komentar_divalidasi(): void
    {
        [, $peserta, $tryout] = $this->persiapan();
        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/feedback", [
            'rating' => 6, 'category' => 'invalid', 'comment' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['rating', 'category', 'comment']);
    }

    public function test_admin_mencari_memfilter_mengekspor_dan_peserta_ditolak(): void
    {
        [$admin, $peserta, $tryout] = $this->persiapan();
        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/feedback", $this->masukan())->assertCreated();
        $this->getJson('/api/admin/tryouts/feedbacks')->assertForbidden();
        $this->getJson('/api/admin/tryouts/feedbacks?export=csv')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/tryouts/feedbacks?search=variasi&rating=4&tryout_id='.$tryout->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.user.name', $peserta->name);
        $this->getJson('/api/admin/tryouts/feedbacks?rating=1')->assertJsonPath('total', 0);
        $export = $this->get('/api/admin/tryouts/feedbacks?export=csv&rating=4')->assertOk();
        $this->assertStringContainsString('Tambahkan variasi soal.', $export->streamedContent());
        $peserta->delete();
        $this->assertDatabaseHas('exam_feedbacks', ['tryout_id' => $tryout->id, 'user_id' => null]);
    }
}
