<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserTryoutAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tryout gratis boleh memilih pembahasannya berbayar atau tidak.
 *
 * Sebelumnya aturannya mati di kode: gratis selalu mengunci pembahasan di balik
 * satu tiket. Tidak semua tryout gratis diniatkan begitu - tryout perkenalan
 * justru dibagikan cuma-cuma sampai ke pembahasannya.
 */
class PembahasanBerbayarTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Tryout} */
    private function siapkan(array $atribut = []): array
    {
        $peserta = User::factory()->create(['kategori' => 'utbk', 'ticket_balance' => 5]);
        $tryout = Tryout::create(array_merge([
            'title' => 'Tryout Gratis', 'kategori' => 'utbk',
            'is_free' => true, 'created_by' => $peserta->id,
        ], $atribut));

        UserTryoutAccess::create([
            'user_id' => $peserta->id, 'tryout_id' => $tryout->id, 'granted_at' => now(),
        ]);

        $subtest = Subtest::create(['name' => 'PU', 'category' => 'TPS', 'exam_type' => 'utbk']);
        TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id,
            'duration_minutes' => 30, 'order_no' => 1, 'is_active' => true]);
        Question::create(['subtest_id' => $subtest->id, 'question_text' => 'Soal',
            'question_type' => 'multiple_choice', 'correct_answer' => 'A',
            'discussion' => 'Ini pembahasannya.', 'is_active' => true]);

        TryoutSession::create([
            'user_id' => $peserta->id, 'tryout_id' => $tryout->id,
            'status' => 'finished', 'attempt_number' => 1,
            'started_at' => now()->subHour(), 'finished_at' => now(),
        ]);

        return [$peserta, $tryout];
    }

    public function test_bawaannya_tetap_menagih_tiket(): void
    {
        [$peserta, $tryout] = $this->siapkan();
        $this->assertTrue($tryout->fresh()->discussion_requires_ticket);

        $this->actingAs($peserta)->getJson("/api/tryouts/{$tryout->id}/review")
            ->assertOk()
            ->assertJsonPath('data.discussion_locked', true)
            ->assertJsonPath('data.review.0.question.discussion', '(Gunakan 1 Tiket untuk pembahasan)');
    }

    public function test_tryout_gratis_berpembahasan_gratis_langsung_terbuka(): void
    {
        [$peserta, $tryout] = $this->siapkan(['discussion_requires_ticket' => false]);

        $this->actingAs($peserta)->getJson("/api/tryouts/{$tryout->id}/review")
            ->assertOk()
            ->assertJsonPath('data.discussion_locked', false)
            ->assertJsonPath('data.review.0.question.discussion', 'Ini pembahasannya.');

        // Tidak ada yang perlu dibuka, jadi tiketnya tidak boleh terpotong.
        $this->postJson("/api/tryouts/{$tryout->id}/unlock-discussion")->assertUnprocessable();
        $this->assertSame(5, $peserta->fresh()->ticket_balance);
    }

    public function test_pembahasan_berbayar_memotong_satu_tiket_lalu_terbuka(): void
    {
        [$peserta, $tryout] = $this->siapkan();

        $this->actingAs($peserta)
            ->postJson("/api/tryouts/{$tryout->id}/unlock-discussion")->assertOk();

        $this->assertSame(4, $peserta->fresh()->ticket_balance);
        $this->getJson("/api/tryouts/{$tryout->id}/review")
            ->assertOk()->assertJsonPath('data.discussion_locked', false);
    }

    public function test_tryout_berbayar_tidak_pernah_menagih_dua_kali(): void
    {
        // Tiketnya sudah terpakai untuk mengerjakan, jadi pembahasannya sudah
        // termasuk - apa pun isi kolom discussion_requires_ticket.
        [$peserta, $tryout] = $this->siapkan([
            'is_free' => false, 'discussion_requires_ticket' => true,
        ]);

        $this->actingAs($peserta)->getJson("/api/tryouts/{$tryout->id}/review")
            ->assertOk()->assertJsonPath('data.discussion_locked', false);

        $this->postJson("/api/tryouts/{$tryout->id}/unlock-discussion")->assertUnprocessable();
        $this->assertSame(5, $peserta->fresh()->ticket_balance);
    }

    public function test_admin_menyimpan_pilihannya_saat_membuat_tryout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/tryouts', [
            'title' => 'Tryout Perkenalan', 'kategori' => 'utbk',
            'is_free' => true, 'discussion_requires_ticket' => false,
        ])->assertCreated()->assertJsonPath('data.discussion_requires_ticket', false);

        // Tidak dikirim berarti bawaannya: menagih tiket, seperti sebelumnya.
        $this->postJson('/api/admin/tryouts', [
            'title' => 'Tryout Biasa', 'kategori' => 'utbk', 'is_free' => true,
        ])->assertCreated()->assertJsonPath('data.discussion_requires_ticket', true);
    }
}
