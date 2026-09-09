<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserTryoutAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpnsUnifiedExamTest extends TestCase
{
    use RefreshDatabase;

    private function persiapan(string $kategori = 'cpns'): array
    {
        $peserta = User::factory()->create(['kategori' => $kategori]);
        $tryout = Tryout::create(['title' => 'Ujian', 'kategori' => $kategori, 'created_by' => $peserta->id]);
        UserTryoutAccess::create(['user_id' => $peserta->id, 'tryout_id' => $tryout->id, 'granted_at' => now()]);
        $soal = [];
        foreach (['TWK', 'TIU', 'TKP'] as $i => $kode) {
            $subtest = Subtest::create(['name' => $kode, 'category' => $kode, 'exam_type' => $kategori]);
            $bagian = TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id,
                'duration_minutes' => 30, 'order_no' => $i + 1, 'is_active' => true]);
            $question = Question::create(['subtest_id' => $subtest->id, 'question_text' => $kode,
                'question_type' => 'multiple_choice', 'correct_answer' => 'A', 'is_active' => true]);
            $soal[] = [$bagian, $question];
        }
        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();

        return [$peserta, $tryout, $soal];
    }

    public function test_seluruh_soal_terbuka_dengan_satu_batas_waktu_yang_tidak_diulang(): void
    {
        [, $tryout, $soal] = $this->persiapan();
        $response = $this->getJson("/api/tryouts/{$tryout->id}/exam")->assertOk()
            ->assertJsonCount(3, 'data.questions')->assertJsonPath('data.questions.2.order_no', 3)
            ->assertJsonPath('data.questions.2.category', 'TKP');
        $batas = $response->json('data.timer.end_time');
        $this->assertGreaterThan(5390, $response->json('data.timer.remaining_seconds'));
        $this->travel(35)->minutes();
        foreach ([2, 0, 1, 0] as $i) {
            [$bagian, $question] = $soal[$i];
            $this->postJson("/api/tryouts/{$tryout->id}/subtests/{$bagian->id}/questions/{$question->id}/answer", ['answer' => 'B'])->assertOk();
        }
        $this->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $this->getJson("/api/tryouts/{$tryout->id}/exam")->assertOk()->assertJsonPath('data.timer.end_time', $batas);
        $this->assertDatabaseCount('user_answers', 3);
    }

    public function test_jawaban_ditolak_setelah_batas_global_atau_selesai_manual(): void
    {
        [, $tryout, $soal] = $this->persiapan();
        [$bagian, $question] = $soal[0];
        $url = "/api/tryouts/{$tryout->id}/subtests/{$bagian->id}/questions/{$question->id}/answer";
        $this->travel(91)->minutes();
        $this->postJson($url, ['answer' => 'A'])->assertUnprocessable();
        $this->assertDatabaseCount('user_answers', 0);
        $this->postJson("/api/tryouts/{$tryout->id}/finish")->assertOk();
        $this->postJson($url, ['answer' => 'A'])->assertUnprocessable();
    }

    public function test_subtes_cpns_tetap_terbuka_setelah_pindah_dan_memakai_tenggat_yang_sama(): void
    {
        [, $tryout, $soal] = $this->persiapan();
        [$pertama] = $soal[0];
        [$terakhir] = $soal[2];
        $awal = $this->postJson("/api/tryouts/{$tryout->id}/subtests/{$pertama->id}/start")->assertOk();
        $this->assertGreaterThan(5390, $awal->json('data.remaining_seconds'));
        $this->postJson("/api/tryouts/{$tryout->id}/subtests/{$pertama->id}/finish")->assertOk();
        $this->travel(35)->minutes();
        $akhir = $this->postJson("/api/tryouts/{$tryout->id}/subtests/{$terakhir->id}/start")->assertOk();
        $this->assertSame($awal->json('data.end_time'), $akhir->json('data.end_time'));
        $this->getJson("/api/tryouts/{$tryout->id}/subtests/{$pertama->id}/exam")->assertOk()
            ->assertJsonPath('data.timer.status', 'in_progress');
    }

    public function test_ujian_terpadu_tidak_tersedia_untuk_utbk_dan_tamu(): void
    {
        [, $tryout] = $this->persiapan('utbk');
        $this->getJson("/api/tryouts/{$tryout->id}/exam")->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson("/api/tryouts/{$tryout->id}/exam")->assertUnprocessable();
    }
}
