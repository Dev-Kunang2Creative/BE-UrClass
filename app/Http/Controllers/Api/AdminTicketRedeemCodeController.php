<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketRedeemCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminTicketRedeemCodeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => TicketRedeemCode::withCount('redemptions')->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:255', 'unique:ticket_redeem_codes,code'],
            'ticket_amount' => ['required', 'integer', 'min:1'],
            'quota' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'expired_at' => ['nullable', 'date'],
        ]);

        $code = TicketRedeemCode::create([
            'code' => strtoupper($validated['code'] ?? Str::random(10)),
            'ticket_amount' => $validated['ticket_amount'],
            'quota' => $validated['quota'],
            'used_count' => 0,
            'is_active' => $validated['is_active'] ?? true,
            'expired_at' => $validated['expired_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Kode redeem tiket berhasil dibuat',
            'data' => $code,
        ], 201);
    }

    public function update(Request $request, TicketRedeemCode $ticketRedeemCode): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', 'unique:ticket_redeem_codes,code,' . $ticketRedeemCode->id],
            'ticket_amount' => ['required', 'integer', 'min:1'],
            'quota' => ['required', 'integer', 'min:' . max(1, $ticketRedeemCode->used_count)],
            'is_active' => ['required', 'boolean'],
            'expired_at' => ['nullable', 'date'],
        ]);

        $ticketRedeemCode->update([
            'code' => strtoupper($validated['code']),
            'ticket_amount' => $validated['ticket_amount'],
            'quota' => $validated['quota'],
            'is_active' => $validated['is_active'],
            'expired_at' => $validated['expired_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Kode redeem tiket berhasil diupdate',
            'data' => $ticketRedeemCode->fresh()->loadCount('redemptions'),
        ]);
    }

    public function destroy(TicketRedeemCode $ticketRedeemCode): JsonResponse
    {
        $ticketRedeemCode->delete();

        return response()->json([
            'message' => 'Kode redeem tiket berhasil dihapus',
        ]);
    }
}
