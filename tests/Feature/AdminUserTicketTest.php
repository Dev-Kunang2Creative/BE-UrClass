<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\AuditLog;
use App\Models\TicketLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penyesuaian tiket oleh admin, dan ringkasan pemakaian AI per pengguna.
 *
 * Tiket bernilai uang bagi peserta, jadi yang dijaga di sini bukan hanya
 * "angkanya bertambah": saldo tidak boleh minus, setiap penyesuaian meninggalkan
 * jejak di dua tempat (riwayat tiket dan log audit), dan peserta tidak bisa
 * menyesuaikan tiketnya sendiri.
 */
class AdminUserTicketTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $siswa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@tiket.test']);
        $this->siswa = User::factory()->create([
            'role' => 'user',
            'email' => 'siswa@tiket.test',
            'ticket_balance' => 3,
        ]);
    }

    public function test_admin_bisa_menambah_tiket(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => 5,
                'reason' => 'Hadiah juara tryout akbar',
            ])
            ->assertOk()
            ->assertJsonPath('data.ticket_balance', 8);

        $this->assertSame(8, (int) $this->siswa->fresh()->ticket_balance);

        $log = TicketLog::where('user_id', $this->siswa->id)->firstOrFail();
        $this->assertSame('credit', $log->type);
        $this->assertSame(5, (int) $log->amount);
        // Dibedakan dari 'paket' dan 'tryout' supaya penyesuaian tangan bisa
        // dipisahkan dari alur normal saat riwayatnya ditinjau.
        $this->assertSame('admin', $log->source);
        $this->assertSame('Hadiah juara tryout akbar', $log->description);
    }

    public function test_admin_bisa_mengurangi_tiket(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => -2,
                'reason' => 'Koreksi pemberian ganda',
            ])
            ->assertOk()
            ->assertJsonPath('data.ticket_balance', 1);

        $this->assertSame('debit', TicketLog::where('user_id', $this->siswa->id)->first()->type);
    }

    /**
     * Saldo negatif akan membuat setiap pemeriksaan "tiket cukup?" berperilaku
     * aneh di tempat yang tidak menduganya. Dibatasi, bukan ditolak: admin yang
     * ingin mengosongkan saldo tidak perlu tahu lebih dulu angka persisnya.
     */
    public function test_pengurangan_dibatasi_sebesar_saldo(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => -99,
                'reason' => 'Kosongkan saldo, akun bermasalah',
            ])
            ->assertOk()
            ->assertJsonPath('data.ticket_balance', 0);

        $this->assertSame(0, (int) $this->siswa->fresh()->ticket_balance);
        // Yang dicatat jumlah yang benar-benar terpotong, bukan yang diminta.
        $this->assertSame(3, (int) TicketLog::where('user_id', $this->siswa->id)->first()->amount);
    }

    public function test_mengurangi_saat_saldo_nol_ditolak(): void
    {
        $this->siswa->update(['ticket_balance' => 0]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => -1,
                'reason' => 'Coba kurangi',
            ])
            ->assertStatus(422);

        $this->assertSame(0, TicketLog::where('user_id', $this->siswa->id)->count());
    }

    /**
     * Penyesuaian tanpa alasan tidak bisa ditinjau siapa pun setelahnya -
     * termasuk oleh yang melakukannya sendiri, sebulan kemudian.
     */
    public function test_alasan_wajib_dan_tidak_boleh_terlalu_singkat(): void
    {
        foreach ([['amount' => 5], ['amount' => 5, 'reason' => 'ok']] as $payload) {
            $this->actingAs($this->admin)
                ->postJson("/api/admin/users/{$this->siswa->id}/tickets", $payload)
                ->assertStatus(422);
        }

        $this->assertSame(3, (int) $this->siswa->fresh()->ticket_balance);
    }

    public function test_nol_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => 0,
                'reason' => 'Tidak melakukan apa-apa',
            ])
            ->assertStatus(422);
    }

    /** Jejaknya ada di dua tempat: riwayat tiket peserta, dan log audit admin. */
    public function test_penyesuaian_tercatat_di_log_audit(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => 4,
                'reason' => 'Pembayaran masuk tapi callback gagal',
            ])
            ->assertOk();

        $audit = AuditLog::latest()->firstOrFail();

        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('tambah_tiket', $audit->action);
        $this->assertStringContainsString('siswa@tiket.test', $audit->description);
        $this->assertStringContainsString('3 -> 7', $audit->description);
        $this->assertStringContainsString('callback gagal', $audit->description);
    }

    public function test_peserta_tidak_bisa_menyesuaikan_tiket(): void
    {
        $this->actingAs($this->siswa)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => 100,
                'reason' => 'Tiket gratis untuk saya',
            ])
            ->assertForbidden();

        $this->assertSame(3, (int) $this->siswa->fresh()->ticket_balance);
    }

    public function test_akun_admin_tidak_disesuaikan(): void
    {
        $lain = User::factory()->create(['role' => 'admin']);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$lain->id}/tickets", [
                'amount' => 5,
                'reason' => 'Coba ke akun admin',
            ])
            ->assertStatus(422);
    }

    /**
     * Token dijumlahkan sesudah pengali model diterapkan - kolomnya menyimpan
     * hasil kali itu - jadi angkanya sebanding dengan halaman pemantauan dan
     * dengan yang benar-benar dipotong dari kuota provider.
     */
    public function test_ringkasan_pemakaian_ai_muncul_di_detail_pengguna(): void
    {
        foreach ([[1000, 200, 5.5, 'ok'], [2000, 400, 11.0, 'ok'], [0, 0, 0, 'failed']] as [$in, $out, $biaya, $status]) {
            AiUsageLog::create([
                'user_id' => $this->siswa->id,
                'provider' => 'openai_compatible',
                'model' => 'kimi-k3',
                'input_tokens' => $in,
                'output_tokens' => $out,
                'token_multiplier' => 2,
                'cost_idr' => $biaya,
                'status' => $status,
            ]);
        }

        $meta = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$this->siswa->id}")
            ->assertOk()
            ->json('meta');

        $this->assertSame(3, $meta['ai_usage']['requests']);
        $this->assertSame(3600, $meta['ai_usage']['total_tokens']);
        $this->assertSame(3000, $meta['ai_usage']['input_tokens']);
        $this->assertSame(600, $meta['ai_usage']['output_tokens']);
        $this->assertSame(16.5, $meta['ai_usage']['cost_idr']);
        $this->assertSame(1, $meta['ai_usage']['failed']);
        // Kuota harian hanya menghitung yang berhasil - permintaan yang gagal
        // karena provider bermasalah tidak memakan kuota peserta.
        $this->assertSame(2, $meta['ai_usage']['used_today']);
        $this->assertNotNull($meta['ai_usage']['last_used_at']);
    }

    public function test_ringkasan_tiket_muncul_di_detail_pengguna(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->siswa->id}/tickets", [
                'amount' => 5,
                'reason' => 'Hadiah lomba',
            ])->assertOk();

        $meta = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$this->siswa->id}")
            ->assertOk()
            ->json('meta');

        $this->assertSame(8, $meta['tickets']['balance']);
        $this->assertSame(5, $meta['tickets']['total_credited']);
        $this->assertSame(0, $meta['tickets']['total_debited']);
        $this->assertCount(1, $meta['tickets']['recent']);
        $this->assertSame('Hadiah lomba', $meta['tickets']['recent'][0]['description']);
    }

    /**
     * Total token muncul di daftar pengguna, bukan hanya di detailnya.
     *
     * Dijumlahkan sesudah pengali model - kolomnya menyimpan hasil kali itu -
     * jadi angkanya sebanding dengan halaman pemantauan.
     */
    public function test_total_token_muncul_di_daftar_pengguna(): void
    {
        foreach ([[1000, 200], [2000, 400]] as [$in, $out]) {
            AiUsageLog::create([
                'user_id' => $this->siswa->id,
                'provider' => 'openai_compatible',
                'model' => 'kimi-k3',
                'input_tokens' => $in,
                'output_tokens' => $out,
                'token_multiplier' => 2,
                'cost_idr' => 1,
                'status' => 'ok',
            ]);
        }

        $rows = collect(
            $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()->json('data')
        )->keyBy('email');

        $this->assertSame(3600, (int) $rows['siswa@tiket.test']['ai_total_tokens']);
        $this->assertSame(2, (int) $rows['siswa@tiket.test']['ai_requests']);

        // Yang belum pernah memakai bernilai nol, bukan null - kolom kosong di
        // tabel terbaca sebagai data yang gagal dimuat.
        $this->assertSame(0, (int) $rows['admin@tiket.test']['ai_total_tokens']);
    }

    /**
     * Satu kueri untuk seluruh halaman, bukan satu per pengguna.
     *
     * Menjumlahkan token dengan memuat log tiap pengguna adalah N+1 yang berhenti
     * bekerja tepat saat tabelnya paling besar - dan tabel itu bertambah satu
     * baris setiap kali ada yang bertanya ke asisten.
     */
    public function test_daftar_pengguna_tidak_mengueri_per_baris(): void
    {
        foreach (range(1, 12) as $i) {
            $user = User::factory()->create(['role' => 'user', 'email' => "n{$i}@tiket.test"]);

            AiUsageLog::create([
                'user_id' => $user->id, 'provider' => 'openai_compatible', 'model' => 'm',
                'input_tokens' => 100, 'output_tokens' => 10, 'cost_idr' => 1, 'status' => 'ok',
            ]);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->getJson('/api/admin/users?per_page=15')->assertOk();

        $kueri = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $q) => str_contains($q, 'ai_usage_logs'));

        DB::disableQueryLog();

        // Tepat satu, dan itu kueri daftar penggunanya sendiri - penjumlahannya
        // ada di dalamnya sebagai subkueri. Kalau berubah jadi N+1, jumlahnya
        // akan sebanyak baris di halaman itu (tiga belas di sini), bukan satu.
        $this->assertCount(
            1,
            $kueri,
            'Token AI diambil lewat lebih dari satu kueri - kemungkinan N+1.',
        );
    }

    /** Pengguna yang belum pernah memakai apa pun tetap punya ringkasan bernilai nol. */
    public function test_pengguna_tanpa_riwayat_tetap_dapat_ringkasan(): void
    {
        $meta = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$this->siswa->id}")
            ->assertOk()
            ->json('meta');

        $this->assertSame(0, $meta['ai_usage']['requests']);
        $this->assertSame(0, $meta['ai_usage']['total_tokens']);
        $this->assertNull($meta['ai_usage']['last_used_at']);
        $this->assertSame([], $meta['tickets']['recent']);
    }
}
