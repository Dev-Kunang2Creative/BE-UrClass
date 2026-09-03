<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\User;
use Database\Seeders\AiSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pemantauan pemakaian AI.
 *
 * Yang dijaga di sini: angkanya benar, biayanya dibekukan saat permintaan
 * terjadi, permintaan gagal tetap tercatat, dan endpoint-nya benar-benar bisa
 * dijalankan di SQLite - dua endpoint admin lain di repo ini tidak bisa diuji
 * karena memakai fungsi khas MySQL, dan pemantauan yang tidak bisa diuji adalah
 * pemantauan yang diam-diam rusak.
 */
class AiUsageMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $siswa;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@usage.test']);
        $this->siswa = User::factory()->create(['role' => 'user', 'email' => 'siswa@usage.test']);

        (new AiSettingSeeder())->run();

        AiSetting::current()->update([
            'endpoint' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-uji',
            'model' => 'openai/gpt-oss-120b',
            'is_active' => true,
            'price_input_per_mtok' => 0.15,
            'price_output_per_mtok' => 0.60,
            'price_cached_per_mtok' => 0.0375,
        ]);
    }

    /** Semua jendela waktu berjalan, termasuk yang mengelompokkan per jam. */
    public function test_semua_jendela_waktu_berjalan(): void
    {
        AiUsageLog::create([
            'user_id' => $this->siswa->id, 'provider' => 'openai_compatible',
            'model' => 'm', 'input_tokens' => 100, 'output_tokens' => 20,
            'cost_usd' => 0.001, 'status' => 'ok',
        ]);

        foreach (['today', '24h', '7d', '30d', '60d'] as $window) {
            $response = $this->actingAs($this->admin)->getJson("/api/admin/ai-usage?window={$window}");

            $response->assertOk()->assertJsonPath('data.window', $window);
            $this->assertNotEmpty($response->json('data.series'), "series kosong untuk {$window}");
        }
    }

    public function test_total_dihitung_benar_per_status(): void
    {
        $buat = fn (string $status, int $in, int $out, float $cost) => AiUsageLog::create([
            'user_id' => $this->siswa->id, 'provider' => 'openai_compatible', 'model' => 'm',
            'input_tokens' => $in, 'output_tokens' => $out, 'cached_tokens' => 0,
            'cost_usd' => $cost, 'status' => $status, 'duration_ms' => 500,
        ]);

        $buat('ok', 1000, 200, 0.00027);
        $buat('ok', 2000, 300, 0.00048);
        $buat('failed', 0, 0, 0);
        $buat('blocked', 0, 0, 0);

        $totals = $this->actingAs($this->admin)
            ->getJson('/api/admin/ai-usage?window=24h')
            ->json('data.totals');

        $this->assertSame(4, $totals['requests']);
        $this->assertSame(2, $totals['ok']);
        $this->assertSame(1, $totals['failed']);
        $this->assertSame(1, $totals['blocked']);
        $this->assertSame(3000, $totals['input_tokens']);
        $this->assertSame(500, $totals['output_tokens']);
        $this->assertSame(0.0008, round($totals['cost_usd'], 4));
        $this->assertSame(500, $totals['avg_duration_ms']);
    }

    /** Ember kosong tetap ada, supaya jeda sepi terlihat di grafik. */
    public function test_ember_kosong_tetap_dikirim_sebagai_nol(): void
    {
        AiUsageLog::create([
            'user_id' => $this->siswa->id, 'provider' => 'openai_compatible', 'model' => 'm',
            'input_tokens' => 10, 'output_tokens' => 5, 'cost_usd' => 0, 'status' => 'ok',
        ]);

        $series = $this->actingAs($this->admin)
            ->getJson('/api/admin/ai-usage?window=24h')
            ->json('data.series');

        // 24 jam per jam: harus ada banyak ember, hampir semuanya nol.
        $this->assertGreaterThan(20, count($series));
        $this->assertSame(1, collect($series)->where('requests', '>', 0)->count());
        $this->assertSame(0, $series[0]['requests']);
    }

    /**
     * Biaya dihitung saat permintaan terjadi dan dibekukan. Harga bisa diubah
     * admin kapan saja, dan biaya yang sudah tercatat tidak boleh berubah.
     */
    public function test_biaya_dibekukan_dan_tidak_ikut_berubah_saat_harga_diubah(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'Jawabannya C.']]],
            'model' => 'openai/gpt-oss-120b',
            'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000],
        ])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertOk();

        $log = AiUsageLog::first();
        // 1 juta input @0.15 + 1 juta output @0.60 = 0.75
        $this->assertSame(0.75, round($log->cost_usd, 4));

        // Harga dinaikkan sepuluh kali; baris yang sudah ada tidak berubah.
        AiSetting::current()->update([
            'price_input_per_mtok' => 1.5,
            'price_output_per_mtok' => 6.0,
        ]);

        $this->assertSame(0.75, round($log->fresh()->cost_usd, 4));
    }

    /** Token cache dihargai terpisah, bukan dihitung dua kali. */
    public function test_token_cache_tidak_dihitung_dua_kali(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => [
                'prompt_tokens' => 1_000_000,
                'completion_tokens' => 0,
                'prompt_tokens_details' => ['cached_tokens' => 800_000],
            ],
        ])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertOk();

        $log = AiUsageLog::first();
        $this->assertSame(1_000_000, $log->input_tokens);
        $this->assertSame(800_000, $log->cached_tokens);
        // 200rb input penuh @0.15 + 800rb cache @0.0375 = 0.03 + 0.03 = 0.06
        // Kalau cache ikut dihargai penuh, hasilnya 0.18.
        $this->assertSame(0.06, round($log->cost_usd, 4));
    }

    public function test_permintaan_gagal_tetap_tercatat(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertStatus(502);

        $log = AiUsageLog::first();
        $this->assertNotNull($log, 'permintaan gagal harus tetap tercatat');
        $this->assertSame('failed', $log->status);
        $this->assertSame(0.0, round($log->cost_usd, 6));
    }

    public function test_kuota_habis_tercatat_sebagai_blocked(): void
    {
        AiSetting::current()->update(['daily_message_limit' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'satu'])->assertOk();
        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'dua'])->assertStatus(429);

        $this->assertSame(1, AiUsageLog::where('status', 'ok')->count());
        $this->assertSame(1, AiUsageLog::where('status', 'blocked')->where('reason', 'quota')->count());
    }

    /** Sebab galat dicatat sebagai kode pendek, bukan pesan mentah provider. */
    public function test_yang_dicatat_kode_sebab_bukan_pesan_provider(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Invalid key sk-or-v1-BOCOR at https://internal.gateway'],
        ], 401)]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertStatus(502);

        $log = AiUsageLog::first();
        $this->assertStringNotContainsString('BOCOR', (string) $log->reason);
        $this->assertStringNotContainsString('internal.gateway', (string) $log->reason);
    }

    public function test_pemakai_terbanyak_dan_rincian_model(): void
    {
        $lain = User::factory()->create(['role' => 'user', 'email' => 'lain@usage.test']);

        foreach (range(1, 3) as $i) {
            AiUsageLog::create([
                'user_id' => $this->siswa->id, 'provider' => 'openai_compatible', 'model' => 'model-a',
                'input_tokens' => 100, 'output_tokens' => 10, 'cost_usd' => 0.001, 'status' => 'ok',
            ]);
        }
        AiUsageLog::create([
            'user_id' => $lain->id, 'provider' => 'openai_compatible', 'model' => 'model-b',
            'input_tokens' => 50, 'output_tokens' => 5, 'cost_usd' => 0.0005, 'status' => 'ok',
        ]);

        $data = $this->actingAs($this->admin)->getJson('/api/admin/ai-usage?window=24h')->json('data');

        $this->assertSame('siswa@usage.test', $data['top_users'][0]['email']);
        $this->assertSame(3, $data['top_users'][0]['requests']);
        $this->assertSame('model-a', $data['by_model'][0]['model']);
        $this->assertSame(330, $data['by_model'][0]['total_tokens']);
    }

    public function test_peserta_tidak_bisa_membaca_pemantauan(): void
    {
        $this->actingAs($this->siswa)->getJson('/api/admin/ai-usage')->assertForbidden();
        // Tamu juga 403, bukan 401: middleware admin di aplikasi ini menolak
        // sebelum lapisan autentikasi menjawab. Yang penting ia ditolak.
        $this->getJson('/api/admin/ai-usage')->assertForbidden();
    }

    public function test_jendela_tak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin/ai-usage?window=selamanya')
            ->assertStatus(422)->assertJsonValidationErrors('window');
    }

    /** Log tidak boleh menyimpan isi pesan maupun jawaban. */
    public function test_log_tidak_menyimpan_isi_percakapan(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'JAWABAN RAHASIA']]]])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', [
            'message' => 'PERTANYAAN RAHASIA',
        ])->assertOk();

        $baris = json_encode(AiUsageLog::first()->toArray());
        $this->assertStringNotContainsString('PERTANYAAN RAHASIA', $baris);
        $this->assertStringNotContainsString('JAWABAN RAHASIA', $baris);
    }
}
