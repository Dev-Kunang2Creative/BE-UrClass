<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use Tests\TestCase;

/**
 * Penguncian metode pembayaran Snap.
 *
 * Kosong berarti "ikuti dashboard Midtrans", supaya mengaktifkan atau
 * menonaktifkan metode tidak perlu deploy. Diisi berarti menguncinya dari sisi
 * aplikasi, apa pun yang aktif di dashboard.
 */
class MetodePembayaranTest extends TestCase
{
    public function test_tanpa_setelan_snap_mengikuti_dashboard(): void
    {
        config(['midtrans.enabled_payments' => []]);

        $params = OrderController::denganMetodePembayaran(['transaction_details' => []]);

        $this->assertArrayNotHasKey('enabled_payments', $params);
    }

    public function test_qris_saja_mengunci_daftarnya(): void
    {
        config(['midtrans.enabled_payments' => ['other_qris']]);

        $params = OrderController::denganMetodePembayaran(['transaction_details' => []]);

        $this->assertSame(['other_qris'], $params['enabled_payments']);
    }

    public function test_beberapa_metode_sekaligus_diteruskan_apa_adanya(): void
    {
        config(['midtrans.enabled_payments' => ['other_qris', 'gopay']]);

        $params = OrderController::denganMetodePembayaran([]);

        $this->assertSame(['other_qris', 'gopay'], $params['enabled_payments']);
    }

    public function test_setelan_dibaca_dari_env_dengan_spasi_dibuang(): void
    {
        // "other_qris, gopay" ditulis dengan spasi setelah koma adalah hal yang
        // wajar terjadi di .env, dan spasi itu akan membuat Midtrans menolak
        // kodenya kalau tidak dibuang.
        putenv('MIDTRANS_ENABLED_PAYMENTS=other_qris, gopay');
        $daftar = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) getenv('MIDTRANS_ENABLED_PAYMENTS')),
        )));

        $this->assertSame(['other_qris', 'gopay'], $daftar);
        putenv('MIDTRANS_ENABLED_PAYMENTS');
    }
}
