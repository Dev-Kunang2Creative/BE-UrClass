<?php

namespace Tests\Feature;

use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserTryoutAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengulang tryout tidak menagih tiket, di jalur mana pun.
 *
 * Aturannya pernah sebaliknya - tiap pengulangan tryout premium memotong satu
 * tiket lagi - lalu diubah atas permintaan pengguna: satu tiket membeli akses
 * ke tryout itu, bukan satu kali pengerjaan.
 */
class PengulanganTryoutGratisTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Tryout} */
    private function siapkan(bool $gratis = false, int $saldo = 3): array
    {
        $peserta = User::factory()->create(['kategori' => 'utbk', 'ticket_balance' => $saldo]);
        $tryout = Tryout::create([
            'title' => 'Tryout', 'kategori' => 'utbk',
            'is_free' => $gratis, 'created_by' => $peserta->id,
        ]);
        UserTryoutAccess::create([
            'user_id' => $peserta->id, 'tryout_id' => $tryout->id, 'granted_at' => now(),
        ]);
        $subtest = Subtest::create(['name' => 'PU', 'category' => 'TPS', 'exam_type' => 'utbk']);
        TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id,
            'duration_minutes' => 30, 'order_no' => 1, 'is_active' => true]);

        return [$peserta, $tryout];
    }

    private function selesaikan(User $peserta, Tryout $tryout): void
    {
        TryoutSession::where('user_id', $peserta->id)
            ->where('tryout_id', $tryout->id)
            ->update(['status' => 'finished', 'finished_at' => now()]);
    }

    public function test_mengulang_tryout_premium_tidak_memotong_tiket(): void
    {
        [$peserta, $tryout] = $this->siapkan(gratis: false, saldo: 3);

        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $this->selesaikan($peserta, $tryout);

        $this->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();

        $this->assertSame(3, $peserta->fresh()->ticket_balance);
        $this->assertSame(2, TryoutSession::where('user_id', $peserta->id)->max('attempt_number'));
    }

    public function test_saldo_tiket_kosong_tidak_menghalangi_pengulangan(): void
    {
        // Dulu ini ditolak 403 "Tiket tidak cukup untuk mengulang tryout ini".
        [$peserta, $tryout] = $this->siapkan(gratis: false, saldo: 0);

        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $this->selesaikan($peserta, $tryout);

        $this->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $this->assertSame(0, $peserta->fresh()->ticket_balance);
    }

    public function test_tryout_gratis_tetap_bisa_diulang_tanpa_biaya(): void
    {
        [$peserta, $tryout] = $this->siapkan(gratis: true, saldo: 2);

        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $this->selesaikan($peserta, $tryout);
        $this->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();

        $this->assertSame(2, $peserta->fresh()->ticket_balance);
    }

    public function test_sesi_yang_belum_selesai_dilanjutkan_bukan_dihitung_percobaan_baru(): void
    {
        [$peserta, $tryout] = $this->siapkan(gratis: false, saldo: 3);

        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $this->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();

        $this->assertSame(1, TryoutSession::where('user_id', $peserta->id)->count());
        $this->assertSame(3, $peserta->fresh()->ticket_balance);
    }

    public function test_tidak_ada_lagi_catatan_tiket_untuk_pengulangan(): void
    {
        [$peserta, $tryout] = $this->siapkan(gratis: false, saldo: 3);

        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $this->selesaikan($peserta, $tryout);
        $this->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();

        $this->assertDatabaseMissing('ticket_logs', [
            'user_id' => $peserta->id,
            'description' => 'Kerjakan ulang: '.$tryout->title,
        ]);
    }
}
