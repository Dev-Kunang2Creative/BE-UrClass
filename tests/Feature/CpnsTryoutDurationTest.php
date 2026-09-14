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

/**
 * Durasi ujian CPNS adalah satu angka di level tryout.
 *
 * Peserta SKD mengerjakan seluruh subtes dalam satu waktu dan bebas berpindah
 * bagian, jadi durasi per subtes tidak pernah dipakai saat ujian berjalan.
 */
class CpnsTryoutDurationTest extends TestCase
{
    use RefreshDatabase;

    private function tryoutCpns(array $atribut = []): array
    {
        $peserta = User::factory()->create(['kategori' => 'cpns']);
        $tryout = Tryout::create(array_merge(
            ['title' => 'SKD', 'kategori' => 'cpns', 'created_by' => $peserta->id],
            $atribut,
        ));
        UserTryoutAccess::create(['user_id' => $peserta->id, 'tryout_id' => $tryout->id, 'granted_at' => now()]);

        foreach (['TWK', 'TIU', 'TKP'] as $i => $kode) {
            $subtest = Subtest::create(['name' => $kode, 'category' => $kode, 'exam_type' => 'cpns']);
            $bagian = TryoutSubtest::create(['tryout_id' => $tryout->id, 'subtest_id' => $subtest->id,
                'duration_minutes' => 30, 'order_no' => $i + 1, 'is_active' => true]);
            Question::create(['subtest_id' => $subtest->id, 'question_text' => $kode,
                'question_type' => 'multiple_choice', 'correct_answer' => 'A', 'is_active' => true]);
        }

        return [$peserta, $tryout];
    }

    public function test_durasi_tryout_menggantikan_penjumlahan_durasi_subtes(): void
    {
        // Subtesnya berjumlah 90 menit; yang berlaku 100 menit milik tryout.
        [$peserta, $tryout] = $this->tryoutCpns(['duration_minutes' => 100]);

        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $sisa = $this->getJson("/api/tryouts/{$tryout->id}/exam")->assertOk()
            ->json('data.timer.remaining_seconds');

        $this->assertGreaterThan(100 * 60 - 10, $sisa);

        // Lewat penjumlahan subtes, belum lewat durasi tryout.
        $this->travel(95)->minutes();
        $this->getJson("/api/tryouts/{$tryout->id}/exam")->assertOk()
            ->assertJsonPath('data.timer.status', 'in_progress');

        $this->travel(10)->minutes();
        $this->getJson("/api/tryouts/{$tryout->id}/exam")->assertUnprocessable();
    }

    public function test_tryout_lama_tanpa_durasi_tetap_memakai_penjumlahan_subtes(): void
    {
        // Kolomnya nullable justru untuk ini: tryout yang dibuat sebelum kolom
        // ada tidak boleh berubah batas waktunya begitu migrasinya jalan.
        [$peserta, $tryout] = $this->tryoutCpns();
        $this->assertNull($tryout->duration_minutes);

        $this->actingAs($peserta)->postJson("/api/tryouts/{$tryout->id}/start")->assertOk();
        $sisa = $this->getJson("/api/tryouts/{$tryout->id}/exam")->assertOk()
            ->json('data.timer.remaining_seconds');

        $this->assertGreaterThan(90 * 60 - 10, $sisa);
        $this->assertLessThanOrEqual(90 * 60, $sisa);
    }

    public function test_tryout_cpns_baru_berdurasi_seratus_menit_dan_utbk_tidak_berdurasi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/tryouts', [
            'title' => 'SKD Perdana', 'kategori' => 'cpns',
        ])->assertCreated()->assertJsonPath('data.duration_minutes', 100);

        $this->postJson('/api/admin/tryouts', [
            'title' => 'UTBK Perdana', 'kategori' => 'utbk',
        ])->assertCreated()->assertJsonPath('data.duration_minutes', null);

        $this->postJson('/api/admin/tryouts', [
            'title' => 'SKD Singkat', 'kategori' => 'cpns', 'duration_minutes' => 75,
        ])->assertCreated()->assertJsonPath('data.duration_minutes', 75);
    }

    public function test_durasi_yang_tidak_dikirim_saat_update_mempertahankan_yang_tersimpan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tryout = Tryout::create(['title' => 'SKD', 'kategori' => 'cpns',
            'duration_minutes' => 75, 'created_by' => $admin->id]);

        $this->actingAs($admin)->putJson("/api/admin/tryouts/{$tryout->id}", [
            'title' => 'SKD Diubah', 'kategori' => 'cpns',
        ])->assertOk()->assertJsonPath('data.duration_minutes', 75);

        $this->putJson("/api/admin/tryouts/{$tryout->id}", [
            'title' => 'SKD Diubah', 'kategori' => 'cpns', 'duration_minutes' => 120,
        ])->assertOk()->assertJsonPath('data.duration_minutes', 120);
    }

    public function test_durasi_di_luar_batas_wajar_ditolak(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/tryouts', [
            'title' => 'SKD', 'kategori' => 'cpns', 'duration_minutes' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');

        $this->postJson('/api/admin/tryouts', [
            'title' => 'SKD', 'kategori' => 'cpns', 'duration_minutes' => 601,
        ])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
    }
}
