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
            'snap_token' => $snapToken
        ], 201);
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

        return Snap::getSnapToken([
            'transaction_details' => [
                'order_id' => $order->order_code,
                'gross_amount' => $order->grand_total,
            ],
            'expiry' => [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit' => 'minute',
                'duration' => self::PAYMENT_EXPIRY_MINUTES,
            ],
            'customer_details' => [
                'first_name' => $request->user()->name ?? 'Siswa',
                'email' => $request->user()->email,
            ],
        ]);
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
