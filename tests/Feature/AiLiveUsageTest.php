<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Support\AiLivePresence;
use Database\Seeders\AiSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pemakaian AI yang sedang berlangsung.
 *
 * Yang dijaga di sini adalah hal yang paling mudah salah tanpa terlihat:
 * pengguna yang permintaannya masih berjalan **harus** muncul walaupun belum
 * punya satu baris log pun, entri keberadaan yang tertinggal karena proses mati
 * **harus** kedaluwarsa sendiri, dan peserta tidak boleh bisa membaca siapa saja
 * yang sedang memakai asisten.
 */
class AiLiveUsageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $siswa;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@live.test']);
        $this->siswa = User::factory()->create(['role' => 'user', 'email' => 'siswa@live.test']);

        (new AiSettingSeeder())->run();

        AiSetting::current()->update([
            'endpoint' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-uji',
            'model' => 'openai/gpt-oss-120b',
            'is_active' => true,
            'price_input_per_mtok' => 2400,
            'price_output_per_mtok' => 9600,
        ]);
    }

    public function test_node_dibuat_per_pengguna_dengan_agregatnya(): void
    {
        $this->catat($this->siswa->id, ['input_tokens' => 100, 'output_tokens' => 40, 'cost_idr' => 0.62]);
        $this->catat($this->siswa->id, ['input_tokens' => 200, 'output_tokens' => 60, 'cost_idr' => 1.06]);
        $this->catat($this->admin->id, ['input_tokens' => 10, 'output_tokens' => 5]);

        $data = $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['nodes']);

        $siswa = collect($data['nodes'])->firstWhere('user_id', $this->siswa->id);

        $this->assertSame(2, $siswa['requests']);
        $this->assertSame(400, $siswa['total_tokens']);
        $this->assertSame(1.68, $siswa['cost_idr']);
        $this->assertSame('siswa@live.test', $siswa['email']);
    }

    /**
     * Inti dari fitur ini. Permintaan yang masih berjalan belum punya baris log -
     * kalau node-nya hanya dibangun dari tabel, orang yang sedang menunggu
     * jawaban justru satu-satunya yang tidak terlihat.
     */
    public function test_pengguna_yang_sedang_menunggu_muncul_walau_belum_punya_log(): void
    {
        AiLivePresence::mulai($this->siswa->id);

        $data = $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['nodes']);
        $this->assertSame('waiting', $data['nodes'][0]['state']);
        $this->assertSame($this->siswa->id, $data['nodes'][0]['user_id']);
        $this->assertSame(0, $data['nodes'][0]['requests']);
    }

    /** Yang sedang menunggu diurutkan di depan, supaya halamannya terbaca sekilas. */
    public function test_yang_menunggu_diurutkan_paling_depan(): void
    {
        $this->catat($this->admin->id, ['created_at' => now()->subSeconds(40)]);
        $this->catat($this->siswa->id, ['created_at' => now()->subSeconds(10)]);

        AiLivePresence::mulai($this->siswa->id);

        $nodes = $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->json('data.nodes');

        $this->assertSame($this->siswa->id, $nodes[0]['user_id']);
        $this->assertSame('waiting', $nodes[0]['state']);
        $this->assertSame('active', $nodes[1]['state']);
    }

    /**
     * Proses yang mati di tengah permintaan tidak pernah membersihkan entrinya.
     * Tanpa kedaluwarsa menurut umur, pengguna itu tampak menunggu selamanya dan
     * halamannya berangsur jadi bohong.
     */
    public function test_entri_menunggu_yang_tertinggal_kedaluwarsa_sendiri(): void
    {
        AiLivePresence::mulai($this->siswa->id);

        $this->travel(3)->minutes();

        $this->assertSame([], AiLivePresence::menunggu());

        $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->assertJsonPath('data.nodes', []);
    }

    /**
     * Node yang tidak aktif lebih dari satu menit hilang dari peta.
     *
     * Ini yang membuat halamannya benar-benar "live": tanpa umur node, peta akan
     * berangsur penuh oleh orang yang sudah lama berhenti memakai, dan berhenti
     * menjawab pertanyaan yang jadi alasannya ada.
     */
    public function test_node_lebih_dari_satu_menit_hilang(): void
    {
        $this->catat($this->siswa->id, ['created_at' => now()->subSeconds(30)]);
        $this->catat($this->admin->id, ['created_at' => now()->subSeconds(90)]);

        $nodes = $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->json('data.nodes');

        $this->assertCount(1, $nodes);
        $this->assertSame($this->siswa->id, $nodes[0]['user_id']);
        $this->assertSame('active', $nodes[0]['state']);

        // Bilangan bulat, bukan float: diffInSeconds mengembalikan float di
        // Carbon 3, dan "30,511503 detik lalu" tidak berarti apa pun.
        $this->assertIsInt($nodes[0]['seconds_ago']);
        $this->assertGreaterThanOrEqual(30, $nodes[0]['seconds_ago']);
        $this->assertLessThan(35, $nodes[0]['seconds_ago']);
    }

    /**
     * Jendelanya tidak bisa diatur dari luar. Kalau bisa, "live" jadi sekadar
     * bawaan yang bisa ditawar - dan node yang bertahan satu jam mengosongkan
     * arti halamannya.
     */
    public function test_jendela_tidak_bisa_diatur_dari_permintaan(): void
    {
        $this->catat($this->siswa->id, ['created_at' => now()->subMinutes(30)]);

        $this->actingAs($this->admin)->getJson('/api/admin/ai-live?minutes=60')
            ->assertOk()
            ->assertJsonPath('data.nodes', [])
            ->assertJsonPath('data.node_ttl_minutes', 1);
    }

    /** Permintaan gagal dihitung terpisah - itu yang dicari saat ada laporan rusak. */
    public function test_permintaan_gagal_dihitung_di_node(): void
    {
        $this->catat($this->siswa->id);
        $this->catat($this->siswa->id, ['status' => 'failed', 'reason' => 'http_502']);
        $this->catat($this->siswa->id, ['status' => 'blocked', 'reason' => 'quota']);

        $node = collect(
            $this->actingAs($this->admin)->getJson('/api/admin/ai-live')->json('data.nodes')
        )->firstWhere('user_id', $this->siswa->id);

        $this->assertSame(3, $node['requests']);
        $this->assertSame(2, $node['failed']);
    }

    public function test_model_terakhir_dilaporkan_bukan_yang_pertama(): void
    {
        $this->catat($this->siswa->id, ['model' => 'lama', 'created_at' => now()->subSeconds(50)]);
        $this->catat($this->siswa->id, ['model' => 'kimi-k3', 'created_at' => now()->subSeconds(10)]);

        $node = collect(
            $this->actingAs($this->admin)->getJson('/api/admin/ai-live')->json('data.nodes')
        )->firstWhere('user_id', $this->siswa->id);

        $this->assertSame('kimi-k3', $node['last_model']);
    }

    /**
     * Daftar ini menyebut nama dan email orang beserta apa yang sedang mereka
     * kerjakan. Ia tidak boleh bisa dibaca peserta.
     */
    public function test_peserta_tidak_bisa_membaca_pemakaian_langsung(): void
    {
        $this->actingAs($this->siswa)->getJson('/api/admin/ai-live')->assertForbidden();
    }

    /**
     * Keberadaan dibersihkan lewat finally, jadi permintaan yang gagal pun tidak
     * meninggalkan peserta dalam keadaan "sedang menunggu".
     */
    public function test_permintaan_gagal_tetap_membersihkan_keberadaan(): void
    {
        Http::fake(['*' => Http::response(['error' => 'meledak'], 500)]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])
            ->assertStatus(502);

        $this->assertSame([], AiLivePresence::menunggu());
    }

    /**
     * Permintaan terakhir dikenali dari siapa yang mengirimnya - nama pengguna,
     * bukan nama model. Halaman ini menjawab "siapa", dan model yang hampir
     * selalu sama tidak memisahkan apa pun.
     */
    public function test_permintaan_terakhir_menyebut_penggunanya(): void
    {
        $this->catat($this->siswa->id);

        $recent = $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->json('data.recent');

        $this->assertSame($this->siswa->name, $recent[0]['name']);
        $this->assertSame('siswa@live.test', $recent[0]['email']);
        $this->assertSame(100, $recent[0]['input_tokens']);
        $this->assertSame(20, $recent[0]['output_tokens']);
        $this->assertArrayNotHasKey('model', $recent[0]);
    }

    /**
     * Tabel permintaan terakhir sengaja tidak mengikuti jendela waktunya: kalau
     * lalu lintas sedang sepi, tabel kosong tidak memberi tahu apa pun,
     * sementara yang dicari justru permintaan terakhir yang masuk.
     */
    public function test_permintaan_terakhir_tidak_dibatasi_jendela(): void
    {
        $this->catat($this->siswa->id, ['created_at' => now()->subHours(3)]);

        $data = $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['recent']);
        // Sementara node-nya tetap mengikuti jendela.
        $this->assertSame([], $data['nodes']);
    }

    public function test_permintaan_terakhir_diurutkan_terbaru_dulu(): void
    {
        $lain = User::factory()->create(['role' => 'user', 'name' => 'Peserta Lama']);

        $this->catat($lain->id, ['created_at' => now()->subMinutes(9)]);
        $this->catat($this->siswa->id, ['created_at' => now()->subSeconds(20)]);

        $recent = $this->actingAs($this->admin)->getJson('/api/admin/ai-live')
            ->assertOk()
            ->json('data.recent');

        $this->assertSame($this->siswa->name, $recent[0]['name']);
        $this->assertSame('Peserta Lama', $recent[1]['name']);
        $this->assertIsInt($recent[0]['seconds_ago']);
    }

    /** @param array<string, mixed> $override */
    private function catat(string $userId, array $override = []): AiUsageLog
    {
        return AiUsageLog::create(array_merge([
            'user_id' => $userId,
            'provider' => 'openai_compatible',
            'model' => 'openai/gpt-oss-120b',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cost_idr' => 0.43,
            'status' => 'ok',
            'duration_ms' => 1200,
            'created_at' => now(),
        ], $override));
    }
}
