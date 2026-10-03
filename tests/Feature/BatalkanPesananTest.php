<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Membatalkan pesanan yang belum dibayar.
 *
 * Kasusnya: peserta salah memilih metode pembayaran. Tanpa pembatalan, ia harus
 * menunggu token Snap kedaluwarsa (15 menit) sebelum bisa memesan ulang, karena
 * pesanan pending yang masih berlaku dipakai ulang berikut tokennya.
 */
class BatalkanPesananTest extends TestCase
{
    use RefreshDatabase;

    private function pesanan(User $user, string $status = 'pending'): Order
    {
        return Order::create([
            'order_code' => 'ORD-'.now()->format('YmdHis').'-UJICOBA',
            'user_id' => $user->id,
            'grand_total' => 99000,
            'currency' => 'IDR',
            'status' => $status,
            'payment_method' => 'midtrans',
        ]);
    }

    public function test_peserta_membatalkan_pesanannya_yang_belum_dibayar(): void
    {
        $peserta = User::factory()->create();
        $order = $this->pesanan($peserta);

        $this->actingAs($peserta)
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_pesanan_yang_sudah_dibayar_tidak_bisa_dibatalkan(): void
    {
        $peserta = User::factory()->create();
        $order = $this->pesanan($peserta, 'paid');

        $this->actingAs($peserta)
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertUnprocessable();

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_pesanan_orang_lain_tidak_bisa_dibatalkan(): void
    {
        $pemilik = User::factory()->create();
        $orangLain = User::factory()->create();
        $order = $this->pesanan($pemilik);

        $this->actingAs($orangLain)
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_setelah_dibatalkan_tidak_ada_lagi_pesanan_pending_yang_dipakai_ulang(): void
    {
        // Inilah yang membebaskan peserta memesan ulang saat itu juga: `store`
        // hanya memakai ulang pesanan berstatus pending berikut token
        // pembayarannya. Begitu dibatalkan, pesanan berikutnya terbit baru.
        $peserta = User::factory()->create();
        $order = $this->pesanan($peserta);

        $this->actingAs($peserta)->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame(
            0,
            Order::where('user_id', $peserta->id)->where('status', 'pending')->count(),
        );
    }

    public function test_pembatalan_tetap_berhasil_walau_midtrans_menolak(): void
    {
        // Transaksinya mungkin belum pernah ada di Midtrans - token dibuat tapi
        // Snap tidak pernah dibuka. Itu bukan alasan menahan pembatalan di sisi
        // kita, yang justru dibutuhkan peserta supaya bisa memesan ulang.
        $peserta = User::factory()->create();
        $order = $this->pesanan($peserta);

        $this->actingAs($peserta)
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
