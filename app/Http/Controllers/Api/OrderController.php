<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;

class OrderController extends Controller
{
    private const PAYMENT_EXPIRY_MINUTES = 15;

    public function index(Request $request): JsonResponse
    {
        $orders = Order::with('items.package')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'string', 'exists:packages,id'],
        ]);

        $package = Package::where('is_active', true)->findOrFail($validated['package_id']);
        $finalPrice = $package->discount_price ?? $package->price;

        $existingOrder = Order::with('items.package')
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->whereHas('items', fn ($query) => $query->where('package_id', $package->id))
            ->latest()
            ->first();

        if ($existingOrder && $this->canReusePendingOrder($existingOrder)) {
            $snapToken = $existingOrder->midtrans_order_id;

            if (! $snapToken) {
                try {
                    $snapToken = $this->createSnapToken($existingOrder, $request);
                    $existingOrder->update(['midtrans_order_id' => $snapToken]);
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Gagal membuka ulang pembayaran. Silakan cek status pembayaran di riwayat.'], 500);
                }
            }

            return response()->json([
                'message' => 'Lanjutkan pembayaran sebelumnya',
                'data' => $existingOrder->fresh()->load('items.package'),
                'snap_token' => $snapToken,
                'snap' => self::snapEnvironment(),
            ]);
        }

        if ($existingOrder) {
            $existingOrder->update(['status' => 'expired']);
        }

        $order = DB::transaction(function () use ($request, $package, $finalPrice) {
            $order = Order::create([
                'order_code' => 'ORD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
                'user_id' => $request->user()->id,
                'grand_total' => $finalPrice,
                'currency' => $package->currency ?? 'IDR',
                'status' => 'pending',
                'payment_method' => 'midtrans',
            ]);

            OrderItem::create([
                'order_id'               => $order->id,
                'package_id'             => $package->id,
                'package_name_snapshot'  => $package->name,
                'ticket_amount_snapshot' => (int) ($package->ticket_amount ?? 0),
                'price'                  => $finalPrice,
                'qty'                    => 1,
                'subtotal'               => $finalPrice,
            ]);

            return $order;
        });

        try {
            $snapToken = $this->createSnapToken($order, $request);
            $order->update(['midtrans_order_id' => $snapToken]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal terhubung ke server pembayaran.'], 500);
        }

        AuditLogger::log('Order', 'create', "Order dibuat: #{$order->order_code} ({$package->name}) Rp" . number_format($finalPrice, 0, ',', '.'), $request->user(), $order);

        return response()->json([
            'message' => 'Silakan lakukan pembayaran',
            'data' => $order->load('items.package'),
            'snap_token' => $snapToken,
            'snap' => self::snapEnvironment(),
        ], 201);
    }

    /**
     * Lingkungan Snap yang menerbitkan token ini, ikut dikirim bersama tokennya.
     *
     * Token Snap hanya berlaku di lingkungan yang menerbitkannya. Selama
     * frontend menentukan sendiri hendak memuat snap.js sandbox atau produksi
     * lewat variabel build-nya sendiri, ada dua saklar terpisah yang harus
     * kebetulan sama - dan begitu berbeda, Snap menjawab "Transaksi tidak
     * ditemukan" atas token yang sebenarnya sah. Dengan mengirim keputusannya
     * dari sini, tidak ada lagi yang perlu ditebak: yang memuat snap.js
     * memakai jawaban dari yang menerbitkan tokennya.
     *
     * client_key memang ditujukan untuk browser - ia yang dipasang Midtrans di
     * atribut data-client-key. Yang rahasia adalah server key, dan itu tidak
     * pernah ikut ke sini.
     */
    private static function snapEnvironment(): array
    {
        return [
            'is_production' => (bool) config('midtrans.is_production'),
            'client_key' => config('midtrans.client_key'),
        ];
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => $order->load('items.package'),
        ]);
    }

    public function verifyPayment(Request $request, Order $order, EnrollmentService $enrollmentService): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        if (in_array($order->status, ['paid', 'approved', 'cancelled', 'rejected'])) {
            $ticketBalance = User::find($order->user_id)?->ticket_balance ?? 0;
            return response()->json([
                'message'        => 'Order sudah diproses.',
                'status'         => $order->status,
                'ticket_balance' => $ticketBalance,
            ]);
        }

        Config::$serverKey   = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');

        try {
            $midtransStatus = Transaction::status($order->order_code);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengecek status pembayaran.'], 502);
        }

        $transactionStatus = $midtransStatus->transaction_status ?? '';
        $fraudStatus       = $midtransStatus->fraud_status ?? 'accept';

        if (in_array($transactionStatus, ['capture', 'settlement']) && $fraudStatus === 'accept') {
            DB::transaction(function () use ($order, $midtransStatus, $enrollmentService) {
                $locked = Order::lockForUpdate()->find($order->id);
                if ($locked->status === 'paid') {
                    return;
                }
                $enrollmentService->approveOrderAndGrantAccess($locked, null);
                $locked->update([
                    'midtrans_transaction_id' => $midtransStatus->transaction_id ?? null,
                    'payment_reference'       => $midtransStatus->payment_type ?? null,
                ]);
            });

            // Kembalikan ticket_balance terbaru dari DB agar frontend
            // tidak perlu optimistic guess — pakai nilai asli
            $freshTicketBalance = User::find($order->user_id)?->ticket_balance ?? 0;

            return response()->json([
                'message'        => 'Pembayaran dikonfirmasi.',
                'status'         => 'paid',
                'ticket_balance' => $freshTicketBalance,
            ]);
        }

        if (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            $status = $transactionStatus === 'expire' ? 'expired' : 'cancelled';
            $order->update(['status' => $status]);
            return response()->json(['message' => 'Pembayaran dibatalkan/kedaluwarsa.', 'status' => $status]);
        }

        return response()->json(['message' => 'Pembayaran belum selesai.', 'status' => $order->status]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Hanya order dengan status pending yang bisa dibatalkan.'], 422);
        }

        // Dibatalkan juga di Midtrans, bukan cuma di sini.
        //
        // Tanpa ini, nomor virtual account atau tagihan yang sudah terbit tetap
        // bisa dibayar peserta setelah ia membatalkan - dan callback pembayaran
        // akan meluluskan order yang sudah berstatus cancelled.
        //
        // Gagalnya dibiarkan: transaksinya mungkin belum pernah ada di Midtrans
        // (token dibuat tapi Snap tidak pernah dibuka), atau statusnya sudah
        // tidak bisa dibatalkan. Dua-duanya bukan alasan menahan pembatalan di
        // sisi kita, yang justru dibutuhkan peserta supaya bisa memesan ulang.
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');

        try {
            Transaction::cancel($order->order_code);
        } catch (\Exception $e) {
            Log::info('Midtrans cancel dilewati', [
                'order' => $order->order_code,
                'alasan' => $e->getMessage(),
            ]);
        }

        $order->update(['status' => 'cancelled']);
        AuditLogger::log('Order', 'cancel', "Order dibatalkan: #{$order->order_code}", $request->user(), $order);

        return response()->json(['message' => 'Order berhasil dibatalkan.']);
    }

    private function createSnapToken(Order $order, Request $request): string
    {
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');

        $pembeli = array_filter([
            'first_name' => $request->user()->name ?? 'Siswa',
            'email' => $request->user()->email,
            // Ikut tampil di dashboard dan memudahkan menghubungi pembeli saat
            // pembayarannya bermasalah. Dibuang kalau kosong, karena Midtrans
            // menolak nomor yang tidak berbentuk.
            'phone' => $request->user()->phone_number,
        ]);

        return Snap::getSnapToken(self::denganMetodePembayaran([
            'transaction_details' => [
                'order_id' => $order->order_code,
                'gross_amount' => $order->grand_total,
            ],
            'expiry' => [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit' => 'minute',
                'duration' => self::PAYMENT_EXPIRY_MINUTES,
            ],
            // Tanpa ini, transaksi di dashboard Midtrans hanya berisi nominal -
            // tidak ada keterangan paket apa yang dibeli, sehingga menelusuri
            // satu pembayaran berarti mencocokkan order_code secara manual.
            'item_details' => self::rincianItem($order),
            'customer_details' => $pembeli,
        ]));
    }

    /**
     * Rincian barang untuk ditampilkan Midtrans.
     *
     * Jumlah harga x kuantitas seluruh baris wajib sama persis dengan
     * gross_amount; kalau meleset satu rupiah pun, Midtrans menolak permintaan
     * tokennya dan pembeli melihat "Gagal terhubung ke server pembayaran".
     * Karena itu kalau jumlahnya tidak cocok - misalnya ada potongan yang tidak
     * terwakili baris mana pun - seluruh rinciannya diganti satu baris sebesar
     * total. Lebih baik rinciannya kasar daripada pembayarannya gagal terbit.
     */
    public static function rincianItem(Order $order): array
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

        $rincian = $items->map(fn ($item) => [
            'id' => (string) ($item->package_id ?? $item->id),
            // Midtrans memotong nama di 50 karakter; dipotong di sini supaya
            // yang tampil tetap kalimat utuh, bukan terpenggal sembarangan.
            'name' => mb_substr((string) ($item->package_name_snapshot ?: 'Paket Tryout'), 0, 50),
            'price' => (int) round((float) $item->price),
            'quantity' => (int) ($item->qty ?: 1),
        ])->values()->all();

        $jumlah = array_sum(array_map(
            fn ($baris) => $baris['price'] * $baris['quantity'],
            $rincian,
        ));

        if ($rincian === [] || $jumlah !== (int) round((float) $order->grand_total)) {
            return [[
                'id' => $order->order_code,
                'name' => 'Paket Tryout UrClass',
                'price' => (int) round((float) $order->grand_total),
                'quantity' => 1,
            ]];
        }

        return $rincian;
    }

    /**
     * Kode metode pembayaran yang dikenal Snap.
     *
     * Dipakai menyaring setelan, bukan membatasi apa yang boleh aktif di
     * dashboard. Kode di luar daftar ini hampir pasti salah ketik.
     */
    public const METODE_DIKENAL = [
        'credit_card', 'gopay', 'shopeepay', 'other_qris', 'bank_transfer',
        'bca_va', 'bni_va', 'bri_va', 'cimb_va', 'permata_va', 'other_va',
        'echannel', 'indomaret', 'alfamart', 'akulaku', 'kredivo', 'dana',
        'uob_ezpay', 'danamon_online', 'bca_klikpay', 'bca_klikbca', 'cimb_clicks',
    ];

    /**
     * Menyisipkan daftar metode pembayaran, kalau memang dikunci.
     *
     * Dibiarkan kosong berarti Snap menampilkan apa pun yang aktif di dashboard
     * Midtrans - dan itu bawaannya, supaya mengaktifkan metode baru tidak perlu
     * deploy. Kuncinya dipakai kalau hanya metode tertentu yang boleh muncul.
     *
     * Kode yang tidak dikenal dibuang, bukan diteruskan. Snap menanggapi kode
     * asing dengan menampilkan NOL metode dan tanpa pesan galat apa pun, jadi
     * satu salah ketik di .env - "qris" alih-alih "other_qris" - akan terbaca
     * seperti akun Midtrans yang belum diaktifkan. Lebih baik jatuh kembali ke
     * perilaku dashboard sambil mencatat sebabnya di log.
     */
    public static function denganMetodePembayaran(array $params): array
    {
        $metode = (array) config('midtrans.enabled_payments');
        $dikenal = array_values(array_intersect($metode, self::METODE_DIKENAL));
        $asing = array_values(array_diff($metode, self::METODE_DIKENAL));

        if ($asing !== []) {
            Log::warning('MIDTRANS_ENABLED_PAYMENTS memuat kode yang tidak dikenal', [
                'diabaikan' => $asing,
                'dipakai' => $dikenal !== [] ? $dikenal : 'mengikuti dashboard Midtrans',
            ]);
        }

        if ($dikenal !== []) {
            $params['enabled_payments'] = $dikenal;
        }

        return $params;
    }

    private function canReusePendingOrder(Order $order): bool
    {
        if ($order->created_at && $order->created_at->lte(now()->subMinutes(self::PAYMENT_EXPIRY_MINUTES))) {
            return false;
        }

        if (! $order->midtrans_order_id) {
            return true;
        }

        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');

        try {
            $midtransStatus = Transaction::status($order->order_code);
        } catch (\Exception) {
            return true;
        }

        $transactionStatus = $midtransStatus->transaction_status ?? '';

        if (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            return false;
        }

        return true;
    }
}
