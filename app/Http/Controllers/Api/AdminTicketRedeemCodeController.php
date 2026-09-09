<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketRedeemCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'expired_at' => self::resolveExpiry($validated['expired_at'] ?? null),
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
            'expired_at' => self::resolveExpiry($validated['expired_at'] ?? null),
        ]);

        return response()->json([
            'message' => 'Kode redeem tiket berhasil diupdate',
            'data' => $ticketRedeemCode->fresh()->loadCount('redemptions'),
        ]);
    }

    /**
     * Tanggal kedaluwarsa berarti "berlaku sampai habis hari itu".
     *
     * Form admin mengirim tanggal saja, dan tanggal polos tersimpan sebagai
     * pukul 00:00 - sehingga kode yang disetel kedaluwarsa hari ini sudah mati
     * sejak tengah malam, berjam-jam sebelum siapa pun sempat memakainya.
     * Waktu yang dikirim lengkap tetap dipakai apa adanya.
     */
    private static function resolveExpiry(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))
            ? Carbon::parse($value)->endOfDay()
            : Carbon::parse($value);
    }

    public function destroy(TicketRedeemCode $ticketRedeemCode): JsonResponse
    {
        $ticketRedeemCode->delete();

        return response()->json([
            'message' => 'Kode redeem tiket berhasil dihapus',
        ]);
    }
}
