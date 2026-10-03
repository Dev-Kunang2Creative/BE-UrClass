<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rincian barang yang dikirim ke Midtrans.
 *
 * Tanpa item_details, transaksi di dashboard Midtrans hanya berisi nominal -
 * tidak ada keterangan paket apa yang dibeli. Pembelian kelas sudah
 * mengirimnya; pembelian paket tidak, dan itu yang diperbaiki di sini.
 */
class RincianItemMidtransTest extends TestCase
{
    use RefreshDatabase;

    private function pesanan(float $total, array $baris): Order
    {
        $user = User::factory()->create();
        $order = Order::create([
            'order_code' => 'ORD-UJI-'.uniqid(),
            'user_id' => $user->id,
            'grand_total' => $total,
            'currency' => 'IDR',
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        foreach ($baris as $isi) {
            $paket = Package::create([
                'name' => $isi['nama'],
                'slug' => 'paket-'.uniqid(),
                'price' => $isi['harga'],
                'ticket_amount' => 5,
                'is_active' => true,
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'package_id' => $paket->id,
                'package_name_snapshot' => $isi['nama'],
                'ticket_amount_snapshot' => 5,
                'price' => $isi['harga'],
                'qty' => $isi['qty'] ?? 1,
                'subtotal' => $isi['harga'] * ($isi['qty'] ?? 1),
            ]);
        }

        return $order->fresh();
    }

    public function test_nama_paket_ikut_terkirim_sebagai_rincian(): void
    {
        $order = $this->pesanan(99000, [['nama' => 'Paket Mulai', 'harga' => 99000]]);

        $rincian = OrderController::rincianItem($order);

        $this->assertCount(1, $rincian);
        $this->assertSame('Paket Mulai', $rincian[0]['name']);
        $this->assertSame(99000, $rincian[0]['price']);
        $this->assertSame(1, $rincian[0]['quantity']);
    }

    public function test_jumlah_rincian_selalu_sama_dengan_total(): void
    {
        // Midtrans menolak token-nya kalau meleset satu rupiah pun, dan pembeli
        // melihat "Gagal terhubung ke server pembayaran".
        $order = $this->pesanan(99000, [['nama' => 'Paket Mulai', 'harga' => 99000]]);

        $rincian = OrderController::rincianItem($order);
        $jumlah = array_sum(array_map(fn ($b) => $b['price'] * $b['quantity'], $rincian));

        $this->assertSame(99000, $jumlah);
    }

    public function test_selisih_harga_diganti_satu_baris_sebesar_total(): void
    {
        // Mis. ada potongan yang tidak terwakili baris mana pun. Lebih baik
        // rinciannya kasar daripada pembayarannya gagal terbit.
        $order = $this->pesanan(199000, [['nama' => 'Paket Fokus', 'harga' => 299000]]);

        $rincian = OrderController::rincianItem($order);

        $this->assertCount(1, $rincian);
        $this->assertSame(199000, $rincian[0]['price']);
        $this->assertSame('Paket Tryout UrClass', $rincian[0]['name']);
    }

    public function test_pesanan_tanpa_baris_tetap_menghasilkan_rincian(): void
    {
        $order = $this->pesanan(50000, []);

        $rincian = OrderController::rincianItem($order);

        $this->assertCount(1, $rincian);
        $this->assertSame(50000, $rincian[0]['price']);
    }

    public function test_nama_panjang_dipotong_di_lima_puluh_karakter(): void
    {
        $panjang = str_repeat('Paket Super Lengkap ', 5);
        $order = $this->pesanan(120000, [['nama' => $panjang, 'harga' => 120000]]);

        $rincian = OrderController::rincianItem($order);

        $this->assertSame(50, mb_strlen($rincian[0]['name']));
    }
}
