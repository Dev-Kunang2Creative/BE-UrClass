<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use Database\Seeders\AiSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asisten AI, dengan penekanan pada satu hal: endpoint dan kunci API tidak
 * boleh bocor ke mana pun.
 *
 * Kelompok test pertama menjaga itu secara langsung - bukan dengan memeriksa
 * apakah controller "kelihatan benar", tapi dengan memeriksa isi respons dan
 * isi kolom database apa adanya.
 */
class AiChatTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'sk-or-v1-rahasia-sekali-jangan-bocor-4f2a';

    protected User $admin;

    protected User $siswa;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@ai.test']);
        $this->siswa = User::factory()->create(['role' => 'user', 'email' => 'siswa@ai.test', 'kategori' => 'utbk']);

        (new AiSettingSeeder())->run();
    }

    private function konfigurasi(array $override = []): AiSetting
    {
        $setting = AiSetting::current();
        $setting->update(array_merge([
            'provider' => AiSetting::PROVIDER_OPENAI_COMPATIBLE,
            'endpoint' => 'https://openrouter.ai/api/v1',
            'api_key' => self::KUNCI,
            'model' => 'openai/gpt-oss-120b',
            'is_active' => true,
        ], $override));

        return $setting->fresh();
    }

    // ---------------------------------------------------------------
    // Kebocoran kredensial
    // ---------------------------------------------------------------

    /** Kunci tersimpan terenkripsi: kolom mentahnya tidak memuat kunci aslinya. */
    public function test_kunci_api_terenkripsi_di_database(): void
    {
        $this->konfigurasi();

        $mentah = DB::table('ai_settings')->value('api_key');

        $this->assertNotSame(self::KUNCI, $mentah);
        $this->assertStringNotContainsString('rahasia-sekali', (string) $mentah);
        // Tapi tetap bisa dibaca aplikasi.
        $this->assertSame(self::KUNCI, AiSetting::current()->api_key);
    }

    /** Endpoint admin tidak pernah mengirim kunci aslinya, hanya bentuk tersamar. */
    public function test_pengaturan_admin_tidak_mengirim_kunci_asli(): void
    {
        $this->konfigurasi();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/ai-settings');

        $response->assertOk();
        $this->assertStringNotContainsString(self::KUNCI, $response->getContent());
        $this->assertStringNotContainsString('rahasia-sekali', $response->getContent());

        $response->assertJsonPath('data.has_api_key', true);
        $this->assertSame('sk-or-…4f2a', $response->json('data.api_key_masked'));
    }

    /** Respons chat tidak memuat kunci maupun endpoint. */
    public function test_respons_chat_tidak_memuat_kunci_atau_endpoint(): void
    {
        $this->konfigurasi();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Jawabannya C.']]]])]);

        $response = $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'Bahas soal ini']);

        $response->assertOk();
        $isi = $response->getContent();
        $this->assertStringNotContainsString(self::KUNCI, $isi);
        $this->assertStringNotContainsString('openrouter.ai', $isi);
    }

    /** Peserta tidak bisa membaca pengaturan, termasuk endpoint-nya. */
    public function test_peserta_tidak_bisa_membaca_pengaturan_ai(): void
    {
        $this->konfigurasi();

        $this->actingAs($this->siswa)->getJson('/api/admin/ai-settings')->assertForbidden();
        $this->actingAs($this->siswa)->putJson('/api/admin/ai-settings', [])->assertForbidden();
        $this->actingAs($this->siswa)->postJson('/api/admin/ai-settings/test')->assertForbidden();
    }

    /** Galat provider tidak meneruskan isi galat aslinya ke peserta. */
    public function test_galat_provider_tidak_membocorkan_apa_pun(): void
    {
        $this->konfigurasi();

        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Invalid API key sk-or-v1-rahasia-sekali-jangan-bocor-4f2a at https://internal.gateway/v1'],
        ], 401)]);

        $response = $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes']);

        $response->assertStatus(502);
        $isi = $response->getContent();
        $this->assertStringNotContainsString(self::KUNCI, $isi);
        $this->assertStringNotContainsString('internal.gateway', $isi);
        $this->assertStringContainsString('Hubungi admin', $isi);
    }

    // ---------------------------------------------------------------
    // SSRF
    // ---------------------------------------------------------------

    /**
     * Endpoint yang menunjuk ke dalam jaringan ditolak. Ini penting karena
     * permintaannya dikirim server: URL ke 169.254.169.254 menjadikan server
     * ini perantara untuk membaca kredensial instans cloud.
     *
     */
    #[DataProvider('endpointBerbahaya')]
    public function test_endpoint_internal_ditolak(string $endpoint): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/admin/ai-settings', $this->payload([
            'endpoint' => $endpoint,
            'api_key' => self::KUNCI,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('endpoint');
    }

    public static function endpointBerbahaya(): array
    {
        return [
            'metadata cloud' => ['https://169.254.169.254/latest/meta-data/'],
            'loopback' => ['https://127.0.0.1/v1'],
            'localhost' => ['https://localhost:3306/v1'],
            'jaringan privat' => ['https://192.168.1.1/v1'],
            'jaringan privat 10' => ['https://10.0.0.5/v1'],
            'domain internal' => ['https://gateway.internal/v1'],
            'tanpa tls' => ['http://openrouter.ai/api/v1'],
        ];
    }

    public function test_endpoint_publik_https_diterima(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/ai-settings', $this->payload([
            'endpoint' => 'https://openrouter.ai/api/v1',
            'api_key' => self::KUNCI,
        ]))->assertOk();
    }

    // ---------------------------------------------------------------
    // Penyuntingan pengaturan
    // ---------------------------------------------------------------

    /**
     * Menyimpan tanpa mengisi kolom kunci mempertahankan kunci yang ada.
     *
     * Ini kesalahan yang paling mudah terjadi di layar seperti ini: form
     * menampilkan kunci tersamar, admin menyimpan tanpa menyentuhnya, dan tanpa
     * penjagaan ini masknya yang tersimpan sebagai kunci.
     */
    public function test_menyimpan_tanpa_kunci_tidak_menghapus_kunci_lama(): void
    {
        $this->konfigurasi();

        $this->actingAs($this->admin)->putJson('/api/admin/ai-settings', $this->payload([
            'model' => 'model/baru',
            'api_key' => '',
        ]))->assertOk();

        $setting = AiSetting::current();
        $this->assertSame(self::KUNCI, $setting->api_key);
        $this->assertSame('model/baru', $setting->model);
    }

    /** Mengirim balik bentuk tersamar tidak menimpanya jadi kunci. */
    public function test_mask_yang_dikirim_balik_tidak_tersimpan_sebagai_kunci(): void
    {
        $this->konfigurasi();

        $this->actingAs($this->admin)->putJson('/api/admin/ai-settings', $this->payload([
            'api_key' => 'sk-or-…4f2a',
        ]))->assertOk();

        $this->assertSame(self::KUNCI, AiSetting::current()->api_key);
    }

    /** Menyalakan tanpa kunci ditolak, bukan dibiarkan gagal saat dipakai. */
    public function test_tidak_bisa_diaktifkan_tanpa_kunci(): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/admin/ai-settings', $this->payload([
            'api_key' => '',
            'is_active' => true,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('api_key');
    }

    // ---------------------------------------------------------------
    // Bentuk permintaan per provider
    // ---------------------------------------------------------------

    /** OpenAI-compatible: Bearer, /chat/completions, persona sebagai pesan system. */
    public function test_bentuk_permintaan_openai_compatible(): void
    {
        $this->konfigurasi(['provider' => AiSetting::PROVIDER_OPENAI_COMPATIBLE]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'Bahas soal'])->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer '.self::KUNCI)
                && $body['messages'][0]['role'] === 'system'
                && str_contains($body['messages'][0]['content'], 'kakak tingkat')
                && $body['messages'][1] === ['role' => 'user', 'content' => 'Bahas soal'];
        });
    }

    /** Anthropic: x-api-key, /v1/messages, persona sebagai field system terpisah. */
    public function test_bentuk_permintaan_anthropic(): void
    {
        $this->konfigurasi([
            'provider' => AiSetting::PROVIDER_ANTHROPIC,
            'endpoint' => 'https://api.anthropic.com',
            'model' => 'claude-opus-5',
        ]);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Jawabannya C.']],
            'model' => 'claude-opus-5',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'Bahas soal'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Jawabannya C.');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', self::KUNCI)
                && $request->hasHeader('anthropic-version', '2023-06-01')
                // Persona sebagai field terpisah, bukan pesan - Anthropic
                // menolak peran system di dalam messages.
                && str_contains($body['system'], 'kakak tingkat')
                && collect($body['messages'])->pluck('role')->doesntContain('system');
        });
    }

    /** Penolakan klasifikator dibedakan dari kerusakan. */
    public function test_penolakan_anthropic_dijelaskan_bukan_dianggap_rusak(): void
    {
        $this->konfigurasi(['provider' => AiSetting::PROVIDER_ANTHROPIC, 'endpoint' => 'https://api.anthropic.com']);
        Http::fake(['*' => Http::response(['content' => [], 'stop_reason' => 'refusal'])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'penyaring keamanan'));
    }

    // ---------------------------------------------------------------
    // Kuota dan riwayat
    // ---------------------------------------------------------------

    public function test_kuota_harian_ditegakkan(): void
    {
        $this->konfigurasi(['daily_message_limit' => 2]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'satu'])
            ->assertOk()->assertJsonPath('data.used_today', 1);
        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'dua'])
            ->assertOk()->assertJsonPath('data.used_today', 2);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tiga'])
            ->assertStatus(429);
    }

    /** Permintaan yang gagal karena provider tidak memakan kuota peserta. */
    public function test_permintaan_gagal_tidak_memakan_kuota(): void
    {
        $this->konfigurasi(['daily_message_limit' => 5]);
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertStatus(502);

        $this->actingAs($this->siswa)->getJson('/api/chat/status')
            ->assertOk()->assertJsonPath('data.used_today', 0);
    }

    /** Riwayat dipotong di server, tidak dipercayakan ke klien. */
    public function test_riwayat_dipotong_di_server(): void
    {
        $this->konfigurasi(['history_limit' => 4]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $riwayat = [];
        foreach (range(1, 20) as $i) {
            $riwayat[] = ['role' => $i % 2 === 1 ? 'user' : 'assistant', 'content' => "pesan {$i}"];
        }

        $this->actingAs($this->siswa)->postJson('/api/chat', [
            'message' => 'terbaru',
            'history' => $riwayat,
        ])->assertOk();

        Http::assertSent(function ($request) {
            // 1 persona + 4 riwayat + 1 pesan baru
            $messages = $request->data()['messages'];

            return count($messages) === 6
                && $messages[1]['content'] === 'pesan 17'
                && end($messages)['content'] === 'terbaru';
        });
    }

    public function test_asisten_nonaktif_menolak_dengan_sopan(): void
    {
        $this->konfigurasi(['is_active' => false]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertStatus(503);
        $this->actingAs($this->siswa)->getJson('/api/chat/status')
            ->assertOk()->assertJsonPath('data.is_available', false);
    }

    public function test_tamu_tidak_bisa_chat(): void
    {
        $this->konfigurasi();

        $this->postJson('/api/chat', ['message' => 'tes'])->assertUnauthorized();
        $this->getJson('/api/chat/status')->assertUnauthorized();
    }

    public function test_pesan_kosong_dan_terlalu_panjang_ditolak(): void
    {
        $this->konfigurasi();

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('message');

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => str_repeat('a', 5000)])
            ->assertStatus(422)->assertJsonValidationErrors('message');
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'provider' => AiSetting::PROVIDER_OPENAI_COMPATIBLE,
            'endpoint' => 'https://openrouter.ai/api/v1',
            'api_key' => '',
            'model' => 'openai/gpt-oss-120b',
            'system_prompt' => AiSettingSeeder::personaKakakTingkat(),
            'max_tokens' => 2048,
            'temperature_x100' => 70,
            'daily_message_limit' => 30,
            'history_limit' => 10,
            'is_active' => false,
        ], $override);
    }
}
