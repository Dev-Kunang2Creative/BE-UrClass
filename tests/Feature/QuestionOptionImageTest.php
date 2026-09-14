<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSubtest;
use App\Models\User;
use App\Models\UserTryoutAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Opsi jawaban A-E boleh bergambar, dengan atau tanpa teks.
 *
 * Penyimpanannya sudah ada sejak awal, tapi gambar itu tidak pernah sampai ke
 * peserta: payload ujian hanya menyalin id, option_key dan option_text. Test
 * ini menjaga jalur lengkapnya - dari unggahan admin sampai muncul di soal.
 */
class QuestionOptionImageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Subtest $subtest;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->subtest = Subtest::create([
            'name' => 'Tes Gambar Opsi',
            'category' => 'TPS',
            'max_questions' => 10,
        ]);
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'question_type' => 'multiple_choice',
            'question_text' => '<p>Bangun mana yang simetris?</p>',
            'correct_answer' => 'A',
            'order_no' => 1,
            'is_active' => true,
            'options' => [
                ['option_key' => 'A', 'option_text' => 'Lingkaran'],
                ['option_key' => 'B', 'option_text' => 'Trapesium'],
            ],
        ], $ubah);
    }

    public function test_opsi_bisa_menyimpan_gambar_bersama_teksnya(): void
    {
        $isi = $this->payload();
        $isi['options'][0]['image'] = UploadedFile::fake()->image('opsi-a.png');

        $this->actingAs($this->admin)
            ->post("/api/admin/subtests/{$this->subtest->id}/questions", $isi)
            ->assertCreated();

        $opsi = Question::query()->firstOrFail()->options()->where('option_key', 'A')->firstOrFail();

        Storage::disk('public')->assertExists($opsi->image);
        $this->assertStringContainsString('/storage/option-images/', $opsi->image_url);
        // Gambar melengkapi teks, bukan menggantikannya.
        $this->assertSame('Lingkaran', $opsi->option_text);
    }

    public function test_gambar_opsi_bertahan_saat_soal_disunting_tanpa_menyentuhnya(): void
    {
        $isi = $this->payload();
        $isi['options'][0]['image'] = UploadedFile::fake()->image('opsi-a.png');
        $this->actingAs($this->admin)
            ->post("/api/admin/subtests/{$this->subtest->id}/questions", $isi)->assertCreated();

        $question = Question::query()->firstOrFail();
        $jalurAwal = $question->options()->where('option_key', 'A')->firstOrFail()->image;

        $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions/{$question->id}",
            $this->payload(['_method' => 'PUT', 'question_text' => '<p>Diubah</p>']),
        )->assertOk();

        $this->assertSame(
            $jalurAwal,
            $question->fresh()->options()->where('option_key', 'A')->firstOrFail()->image,
        );
        Storage::disk('public')->assertExists($jalurAwal);
    }

    public function test_gambar_opsi_bisa_dilepas_dan_berkasnya_ikut_terhapus(): void
    {
        $isi = $this->payload();
        $isi['options'][0]['image'] = UploadedFile::fake()->image('opsi-a.png');
        $this->actingAs($this->admin)
            ->post("/api/admin/subtests/{$this->subtest->id}/questions", $isi)->assertCreated();

        $question = Question::query()->firstOrFail();
        $jalurAwal = $question->options()->where('option_key', 'A')->firstOrFail()->image;

        $hapus = $this->payload(['_method' => 'PUT']);
        $hapus['options'][0]['delete_image'] = '1';

        $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions/{$question->id}",
            $hapus,
        )->assertOk();

        $this->assertNull($question->fresh()->options()->where('option_key', 'A')->firstOrFail()->image);
        Storage::disk('public')->assertMissing($jalurAwal);
    }

    public function test_gambar_opsi_ikut_terkirim_ke_peserta_di_ujian_cpns_dan_utbk(): void
    {
        $isi = $this->payload();
        $isi['options'][0]['image'] = UploadedFile::fake()->image('opsi-a.png');
        $this->actingAs($this->admin)
            ->post("/api/admin/subtests/{$this->subtest->id}/questions", $isi)->assertCreated();

        foreach (['cpns', 'utbk'] as $kategori) {
            $peserta = User::factory()->create(['kategori' => $kategori]);
            $tryout = Tryout::create(['title' => 'Ujian', 'kategori' => $kategori, 'created_by' => $peserta->id]);
            UserTryoutAccess::create(['user_id' => $peserta->id, 'tryout_id' => $tryout->id, 'granted_at' => now()]);
            $bagian = TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $this->subtest->id,
                'duration_minutes' => 30, 'order_no' => 1, 'is_active' => true]);

            $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();

            // UTBK dikerjakan subtes per subtes, jadi sesinya dimulai dulu.
            if ($kategori === 'utbk') {
                $this->postJson("/api/tryouts/{$tryout->id}/subtests/{$bagian->id}/start")->assertOk();
            }

            $url = $kategori === 'cpns'
                ? "/api/tryouts/{$tryout->id}/exam"
                : "/api/tryouts/{$tryout->id}/subtests/{$bagian->id}/exam";

            $this->getJson($url)->assertOk()->assertJsonPath(
                'data.questions.0.options.0.image_url',
                fn ($nilai) => is_string($nilai) && str_contains($nilai, '/storage/option-images/'),
            );
        }
    }

    public function test_gambar_opsi_ikut_terhapus_saat_soalnya_dihapus(): void
    {
        $isi = $this->payload();
        $isi['options'][0]['image'] = UploadedFile::fake()->image('opsi-a.png');
        $this->actingAs($this->admin)
            ->post("/api/admin/subtests/{$this->subtest->id}/questions", $isi)->assertCreated();

        $question = Question::query()->firstOrFail();
        $jalur = $question->options()->where('option_key', 'A')->firstOrFail()->image;

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/subtests/{$this->subtest->id}/questions/{$question->id}")
            ->assertOk();

        Storage::disk('public')->assertMissing($jalur);
    }
}
