<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TicketLog;
use App\Models\User;
use App\Models\UserPackageEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnrollmentService
{
    public function approveOrderAndGrantAccess(Order $order, ?string $adminId = null): Order
    {
        return DB::transaction(function () use ($order, $adminId) {
            $order->load('items.package');

            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'approved_at' => now(),
                'approved_by' => $adminId,
            ]);

            $user = User::lockForUpdate()->find($order->user_id);

            foreach ($order->items as $item) {
                // Gunakan snapshot agar tidak terpengaruh perubahan harga/tiket oleh admin
                $package = $item->package;
                $snapshotTicketAmount = (int) ($item->ticket_amount_snapshot ?? 0);
                $ticketAmount = $snapshotTicketAmount > 0
                    ? $snapshotTicketAmount
                    : (int) ($package?->ticket_amount ?? 0);

                if ($package) {
                    UserPackageEnrollment::firstOrCreate(
                        [
                            'user_id'    => $user->id,
                            'package_id' => $package->id,
                        ],
                        [
                            'order_id'    => $order->id,
                            'enrolled_at' => now(),
                        ]
                    );
                } else {
                    Log::warning('EnrollmentService: package null untuk order item', [
                        'order_id'      => $order->id,
                        'order_item_id' => $item->id,
                    ]);
                }

                if ($ticketAmount > 0) {
                    $user->ticket_balance += $ticketAmount;
                    TicketLog::create([
                        'user_id'     => $user->id,
                        'type'        => 'credit',
                        'amount'      => $ticketAmount,
                        'source'      => 'paket',
                        'description' => $item->package_name_snapshot,
                    ]);

                    Log::info('EnrollmentService: ticket granted', [
                        'order_code'    => $order->order_code,
                        'user_id'       => $user->id,
                        'order_item_id' => $item->id,
                        'amount'        => $ticketAmount,
                    ]);
                } else {
                    Log::warning('EnrollmentService: ticket amount kosong, tiket tidak ditambahkan', [
                        'order_code'             => $order->order_code,
                        'order_item_id'          => $item->id,
                        'package_id'             => $item->package_id,
                        'ticket_amount_snapshot' => $item->ticket_amount_snapshot,
                        'package_ticket_amount'  => $package?->ticket_amount,
                    ]);
                }
            }

            $user->save();

            return $order->fresh(['items.package']);
        });
    }

    public function rejectOrder(Order $order, ?string $note = null): Order
    {
        $order->update([
            'status' => 'rejected',
            'admin_note' => $note,
        ]);

        return $order->fresh(['items.package']);
    }
}
