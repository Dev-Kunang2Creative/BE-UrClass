<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KelasOrder;
use App\Models\Order;
use App\Models\TicketLog;
use App\Models\User;
use App\Models\UserKelasEnrollment;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
    public function handle(Request $request, EnrollmentService $enrollmentService)
    {
        Log::info('Midtrans Webhook Received', $request->all());

        $serverKey         = config('midtrans.server_key');
        $orderCode         = $request->order_id;
        $statusCode        = $request->status_code;
        $grossAmount       = $request->gross_amount;
        $signatureKey      = $request->signature_key;
        $transactionStatus = $request->transaction_status;
        $fraudStatus       = $request->fraud_status ?? null;

        // Validasi Signature
        $validSignature = hash('sha512', $orderCode . $statusCode . $grossAmount . $serverKey);

        if ($validSignature !== $signatureKey) {
            Log::warning('Midtrans Invalid Signature', ['order' => $orderCode]);
            return response()->json(['message' => 'Invalid signature key'], 403);
        }

        // Routing berdasarkan prefix
        if (str_starts_with($orderCode, 'KLAS-')) {
            return $this->handleKelasCallback($request, $orderCode, $grossAmount, $transactionStatus, $fraudStatus);
        }

        // Cari Order
        $order = Order::where('order_code', $orderCode)->first();

        if (!$order) {
            // Return 200 agar Midtrans tidak terus retry untuk order yang memang tidak ada
            Log::error('Midtrans Order Not Found', ['order' => $orderCode]);
            return response()->json(['message' => 'Order tidak ditemukan'], 200);
        }

        // Validasi Nominal
        if ((float) $order->grand_total !== (float) $grossAmount) {
            Log::critical('Midtrans Gross Amount Mismatch!', [
                'order'          => $orderCode,
                'db_price'       => $order->grand_total,
                'midtrans_price' => $grossAmount,
            ]);
            return response()->json(['message' => 'Nominal pembayaran tidak valid'], 400);
        }

        // Proses berdasarkan status
        try {
            if ($transactionStatus === 'settlement') {
                $this->processSuccessOrder($order, $request, $enrollmentService);
            } elseif ($transactionStatus === 'capture') {
                if ($fraudStatus === 'accept') {
                    $this->processSuccessOrder($order, $request, $enrollmentService);
                } elseif ($fraudStatus === 'challenge') {
                    Log::warning('Midtrans Fraud Challenge', ['order' => $orderCode]);
                    // Tidak ubah status — tunggu keputusan fraud review dari Midtrans
                }
            } elseif ($transactionStatus === 'pending') {
                // QRIS/transfer yang belum settle — log saja, jangan ubah status order
                Log::info('Midtrans Payment Pending', [
                    'order'        => $orderCode,
                    'payment_type' => $request->payment_type,
                ]);
            } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire', 'failure'])) {
                DB::transaction(function () use ($order, $transactionStatus) {
                    $locked = Order::lockForUpdate()->find($order->id);
                    if ($locked->status !== 'paid') {
                        $locked->update(['status' => 'cancelled']);
                        Log::info('Midtrans Order Cancelled/Expired', [
                            'order'  => $locked->order_code,
                            'reason' => $transactionStatus,
                        ]);
                    }
                });
            } else {
                Log::info('Midtrans Unhandled Status', [
                    'order'  => $orderCode,
                    'status' => $transactionStatus,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Midtrans Webhook Processing Failed', [
                'order'  => $orderCode,
                'status' => $transactionStatus,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);
            // Return 500 agar Midtrans retry otomatis
            return response()->json(['message' => 'Gagal memproses pembayaran, akan dicoba ulang'], 500);
        }

        return response()->json(['message' => 'Callback diproses']);
    }

    private function handleKelasCallback(
        Request $request,
        string $orderCode,
        $grossAmount,
        string $transactionStatus,
        ?string $fraudStatus
    ) {
        $kelasOrder = KelasOrder::where('order_code', $orderCode)->first();

        if (!$kelasOrder) {
            // Return 200 agar Midtrans tidak terus retry
            Log::error('Midtrans Kelas Order Not Found', ['order' => $orderCode]);
            return response()->json(['message' => 'Order tidak ditemukan'], 200);
        }

        if ((float) $kelasOrder->grand_total !== (float) $grossAmount) {
            Log::critical('Midtrans Kelas Gross Amount Mismatch!', [
                'order'          => $orderCode,
                'db_price'       => $kelasOrder->grand_total,
                'midtrans_price' => $grossAmount,
            ]);
            return response()->json(['message' => 'Nominal tidak valid'], 400);
        }

        try {
            if ($transactionStatus === 'settlement' || ($transactionStatus === 'capture' && $fraudStatus === 'accept')) {
                $this->processKelasOrder($kelasOrder, $request->transaction_id, $request->payment_type);
            } elseif ($transactionStatus === 'pending') {
                Log::info('Midtrans Kelas Payment Pending', [
                    'order'        => $orderCode,
                    'payment_type' => $request->payment_type,
                ]);
            } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire', 'failure'])) {
                DB::transaction(function () use ($kelasOrder, $transactionStatus) {
                    $locked = KelasOrder::lockForUpdate()->find($kelasOrder->id);
                    if ($locked->status !== 'paid') {
                        $locked->update(['status' => 'cancelled']);
                        Log::info('Midtrans Kelas Order Cancelled/Expired', [
                            'order'  => $locked->order_code,
                            'reason' => $transactionStatus,
                        ]);
                    }
                });
            }
        } catch (\Exception $e) {
            Log::error('Midtrans Kelas Webhook Processing Failed', [
                'order'  => $orderCode,
                'status' => $transactionStatus,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Gagal memproses, akan dicoba ulang'], 500);
        }

        return response()->json(['message' => 'Callback diproses']);
    }

    private function processKelasOrder(KelasOrder $order, ?string $transactionId = null, ?string $paymentType = null): void
    {
        DB::transaction(function () use ($order, $transactionId, $paymentType) {
            $locked = KelasOrder::lockForUpdate()->find($order->id);
            if ($locked->status === 'paid') {
                return;
            }

            $locked->update([
                'status'                  => 'paid',
                'paid_at'                 => now(),
                'midtrans_transaction_id' => $transactionId,
                'payment_reference'       => $paymentType,
            ]);

            UserKelasEnrollment::firstOrCreate(
                [
                    'user_id'  => $locked->user_id,
                    'kelas_id' => $locked->kelas_id,
                ],
                [
                    'kelas_order_id' => $locked->id,
                    'enrolled_at'    => now(),
                ]
            );

            $user = User::lockForUpdate()->find($locked->user_id);
            $ticketAmount = (int) ($locked->kelas->ticket_amount ?? 0);

            if ($ticketAmount > 0) {
                $user->ticket_balance += $ticketAmount;
                TicketLog::create([
                    'user_id'     => $user->id,
                    'type'        => 'credit',
                    'amount'      => $ticketAmount,
                    'source'      => 'kelas',
                    'description' => $locked->kelas->name,
                ]);

                Log::info('Midtrans Kelas: ticket granted', [
                    'order_code' => $locked->order_code,
                    'user_id'    => $user->id,
                    'amount'     => $ticketAmount,
                ]);
            }
            $user->save();
        });
    }

    private function processSuccessOrder($order, $request, $enrollmentService)
    {
        DB::transaction(function () use ($order, $request, $enrollmentService) {
            $locked = Order::lockForUpdate()->find($order->id);
            if ($locked->status === 'paid') {
                return;
            }

            // approveOrderAndGrantAccess: set status=paid, grant tiket, buat enrollment
            $enrollmentService->approveOrderAndGrantAccess($locked, null);

            // Refresh agar tidak overwrite perubahan dari service dengan data stale
            $locked->refresh();

            // Simpan data referensi Midtrans yang tidak dihandle oleh service
            $locked->update([
                'midtrans_transaction_id' => $request->transaction_id,
                'payment_reference'       => $request->payment_type,
            ]);
        });
    }
}
