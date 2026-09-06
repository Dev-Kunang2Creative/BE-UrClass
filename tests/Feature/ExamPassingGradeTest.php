<?php

namespace Tests\Feature;

use App\Models\ExamSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExamPassingGradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_peserta_membaca_ambang_global_untuk_tampilan_ujian(): void
    {
        $this->getJson('/api/settings/exam-passing-grades')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->getJson('/api/settings/exam-passing-grades')->assertOk()
            ->assertJsonPath('data.skd_passing_grade_twk', 65);
    }

    public function test_admin_membaca_nilai_bawaan_dan_memperbarui_cache_global(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/settings/exam-passing-grades')
            ->assertOk()->assertJsonPath('data.skd_passing_grade_twk', 65)
            ->assertJsonPath('data.skd_passing_grade_tiu', 80)
            ->assertJsonPath('data.skd_passing_grade_tkp', 166);

        $this->assertSame(['twk' => 65, 'tiu' => 80, 'tkp' => 166], ExamSetting::getSkdPassingGrades());
        $this->assertTrue(Cache::has('global_exam_settings'));
        $this->putJson('/api/admin/settings/exam-passing-grades', [
            'skd_passing_grade_twk' => 70, 'skd_passing_grade_tiu' => 85, 'skd_passing_grade_tkp' => 170,
        ])->assertOk();
        $this->assertSame(['twk' => 70, 'tiu' => 85, 'tkp' => 170], ExamSetting::getSkdPassingGrades());
    }

    public function test_peserta_dan_tamu_tidak_boleh_mengelola_ambang(): void
    {
        $this->getJson('/api/admin/settings/exam-passing-grades')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'user']));
        $this->getJson('/api/admin/settings/exam-passing-grades')->assertForbidden();
        $this->putJson('/api/admin/settings/exam-passing-grades', [])->assertForbidden();
    }

    public function test_ambang_harus_bilangan_bulat_nonnegatif_dan_lengkap(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->putJson('/api/admin/settings/exam-passing-grades', [
                'skd_passing_grade_twk' => -1, 'skd_passing_grade_tiu' => 2.5,
            ])->assertUnprocessable()->assertJsonValidationErrors([
                'skd_passing_grade_twk', 'skd_passing_grade_tiu', 'skd_passing_grade_tkp',
            ]);
    }
}
