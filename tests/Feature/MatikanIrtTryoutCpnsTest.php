<?php

namespace Tests\Feature;

use App\Models\Tryout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MatikanIrtTryoutCpnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tryout_cpns_lama_dimatikan_irt_nya_dan_utbk_tidak_disentuh(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cpns = Tryout::create(['title' => 'SKD lama', 'created_by' => $admin->id, 'kategori' => 'cpns']);
        $utbkMati = Tryout::create(['title' => 'UTBK', 'created_by' => $admin->id, 'kategori' => 'utbk']);

        // Keadaan sebelum aturannya ditegakkan, ditulis langsung tanpa controller.
        DB::table('tryouts')->where('id', $cpns->id)->update(['use_irt' => true]);
        DB::table('tryouts')->where('id', $utbkMati->id)->update(['use_irt' => false]);

        (require database_path('migrations/2026_10_03_100000_matikan_irt_pada_tryout_cpns.php'))->up();

        $this->assertFalse($cpns->fresh()->use_irt);
        $this->assertFalse($utbkMati->fresh()->use_irt);
    }
}
