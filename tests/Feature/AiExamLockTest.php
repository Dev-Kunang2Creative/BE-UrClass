<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\Subtest;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Support\ActiveExam;
use Database\Seeders\AiSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asisten AI ditutup selama tryout berlangsung.
 *
 * Yang dijaga di sini bukan hanya "diblokir saat ujian", tapi dua hal yang
 * lebih mudah rusak tanpa terlihat: batasnya ditegakkan **server** dan bukan
 * hanya disembunyikan di antarmuka, dan sesi yang ditinggalkan **tidak**
 * mengunci asisten selamanya.
 */
class AiExamLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $siswa;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->siswa = User::factory()->create(['role' => 'user']);

        (new AiSettingSeeder())->run();

        AiSetting::current()->update([
            'endpoint' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-uji',
            'model' => 'openai/gpt-oss-120b',
            'is_active' => true,
        ]);

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'Jawaban: 36']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ])]);
    }

    public function test_chat_ditolak_selama_tryout_berjalan(): void
    {
        $this->mulaiTryout();

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'bahas soal ini'])
            ->assertStatus(403)
            ->assertJsonPath('data.exam.title', 'Tryout UTBK Perdana');

        // Tidak ada permintaan yang sampai ke provider - blokirnya di depan,
        // bukan sesudah token terpakai.
        Http::assertNothingSent();
    }

    /** Diblokir sebelum kuota harian tersentuh: ujian bukan alasan memakan kuota. */
    public function test_blokir_ujian_tidak_memakan_kuota_harian(): void
    {
        $this->mulaiTryout();

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertStatus(403);

        $this->actingAs($this->siswa)->getJson('/api/chat/status')
            ->assertOk()
            ->assertJsonPath('data.used_today', 0);
    }

    /** Blokirnya tercatat, supaya terlihat di pemantauan kalau sering terjadi. */
    public function test_blokir_ujian_dicatat(): void
    {
        $this->mulaiTryout();

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertStatus(403);

        $log = AiUsageLog::where('user_id', $this->siswa->id)->firstOrFail();

        $this->assertSame('blocked', $log->status);
        $this->assertSame('exam', $log->reason);
        $this->assertSame(0, $log->input_tokens);
    }

    public function test_status_memberi_tahu_antarmuka_alasannya(): void
    {
        $this->mulaiTryout();

        $this->actingAs($this->siswa)->getJson('/api/chat/status')
            ->assertOk()
            // Tetap true: asistennya memang tersedia, yang menghalangi ujiannya.
            // Menggabungkan keduanya membuat antarmuka mengatakan "belum
            // tersedia" untuk sesuatu yang tersedia lagi setengah jam kemudian.
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.is_blocked_by_exam', true)
            ->assertJsonPath('data.exam.title', 'Tryout UTBK Perdana');
    }

    public function test_di_luar_tryout_chat_berjalan_normal(): void
    {
        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'bahas soal ini'])
            ->assertOk();

        $this->actingAs($this->siswa)->getJson('/api/chat/status')
            ->assertJsonPath('data.is_blocked_by_exam', false)
            ->assertJsonPath('data.exam', null);
    }

    public function test_tryout_yang_sudah_selesai_tidak_memblokir(): void
    {
        $session = $this->mulaiTryout();
        $session->update(['status' => 'finished', 'finished_at' => now()]);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertOk();
    }

    /**
     * Sesi tryout tidak punya kedaluwarsa: peserta yang menutup tab di tengah
     * ujian meninggalkan baris `in_progress` untuk selamanya. Tanpa batas waktu
     * sendiri, satu tryout yang ditinggalkan mengunci asisten untuk akun itu
     * tanpa cara memulihkannya - dan tanpa satu pun pesan yang menjelaskan
     * sebabnya.
     */
    public function test_sesi_terlantar_tidak_mengunci_asisten_selamanya(): void
    {
        $this->mulaiTryout(mulai: now()->subDays(3));

        $this->assertNull(ActiveExam::for($this->siswa->id));

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertOk();
    }

    /** Masih dalam durasi subtes plus kelonggaran: itu ujian yang benar-benar berjalan. */
    public function test_sesi_yang_masih_dalam_durasinya_tetap_memblokir(): void
    {
        // Total 90 menit, dimulai 30 menit lalu.
        $this->mulaiTryout(mulai: now()->subMinutes(30));

        $this->assertNotNull(ActiveExam::for($this->siswa->id));

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertStatus(403);
    }

    /** Ujian peserta lain bukan urusan akun ini. */
    public function test_tryout_orang_lain_tidak_memblokir(): void
    {
        $lain = User::factory()->create(['role' => 'user']);
        $this->mulaiTryout(user: $lain);

        $this->actingAs($this->siswa)->postJson('/api/chat', ['message' => 'tes'])->assertOk();
    }

    private function mulaiTryout(?User $user = null, $mulai = null): TryoutSession
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $tryout = Tryout::create([
            'title' => 'Tryout UTBK Perdana',
            'category' => 'utbk',
            'is_free' => true,
            'is_published' => true,
            'start_date' => now()->subHour(),
            'end_date' => now()->addDay(),
            'created_by' => $admin->id,
        ]);

        foreach ([['Penalaran Umum', 45], ['Literasi', 45]] as [$nama, $durasi]) {
            $subtest = Subtest::create([
                'name' => $nama,
                'category' => 'utbk',
                'max_questions' => 10,
            ]);

            TryoutSubtest::create([
                'tryout_id' => $tryout->id,
                'subtest_id' => $subtest->id,
                'duration_minutes' => $durasi,
                'is_active' => true,
            ]);
        }

        return TryoutSession::create([
            'user_id' => ($user ?? $this->siswa)->id,
            'tryout_id' => $tryout->id,
            'attempt_number' => 1,
            'started_at' => $mulai ?? now(),
            'status' => 'in_progress',
        ]);
    }
}
