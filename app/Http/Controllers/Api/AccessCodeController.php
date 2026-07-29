<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\TicketLog;
use App\Models\TicketRedeemCode;
use App\Models\TicketRedeemRedemption;
use App\Models\User;
use App\Models\UserTryoutAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessCodeController extends Controller
{
    //
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        $accessCode = AccessCode::with('tryout')
            ->where('code', $validated['code'])
            ->first();

        if (! $accessCode) {
            return $this->redeemTicketCode($request, $validated['code']);
        }
        
        if (! $accessCode->is_active || $accessCode->isExpired()) {
            return response()->json([
                'message' => 'Kode akses tidak bisa digunakan',
                'error_code' => 'inactive',
            ], 422);
        }

        if ($accessCode->used_count >= $accessCode->max_usage) {
            return response()->json([
                'message' => 'Kuota voucher sudah habis',
                'error_code' => 'quota_exhausted',
            ], 422);
        }

        $alreadyHasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $accessCode->tryout_id)
            ->exists();

        if($alreadyHasAccess) {
            return response()->json([
                'message' => 'Anda sudah memiliki akses untuk tryout ini',
            ], 422);
        }

        DB::transaction(function () use ($user, $accessCode) {
            UserTryoutAccess::create([
                'user_id' => $user->id,
                'tryout_id' => $accessCode->tryout_id,
                'access_code_id' => $accessCode->id,
                'granted_at' => now(),
            ]);

            $accessCode->increment('used_count');
        });

        return response()->json([
            'message' => 'Kode akses berhasil digunakan',
            'data' => [
                'tryout_id' => $accessCode->tryout_id,
                'tryout_title' => $accessCode->tryout->title,
            ],
        ]);
    }

    private function redeemTicketCode(Request $request, string $code): JsonResponse
    {
        $user = $request->user();

        $redeemCode = TicketRedeemCode::where('code', strtoupper($code))->first();

        if (! $redeemCode) {
            return response()->json([
                'message' => 'Kode akses tidak ditemukan',
                'error_code' => 'not_found',
            ], 404);
        }

        if (! $redeemCode->is_active || $redeemCode->isExpired()) {
            return response()->json([
                'message' => 'Kode redeem tidak bisa digunakan',
                'error_code' => 'inactive',
            ], 422);
        }

        if (TicketRedeemRedemption::where('ticket_redeem_code_id', $redeemCode->id)
            ->where('user_id', $user->id)
            ->exists()) {
            return response()->json([
                'message' => 'Voucher sudah terpakai',
                'error_code' => 'already_used',
            ], 422);
        }

        if (! $redeemCode->hasQuota()) {
            return response()->json([
                'message' => 'Kuota voucher sudah habis',
                'error_code' => 'quota_exhausted',
            ], 422);
        }

        $ticketBalance = DB::transaction(function () use ($user, $redeemCode) {
            $lockedCode = TicketRedeemCode::whereKey($redeemCode->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedCode->hasQuota()) {
                return null;
            }

            if (TicketRedeemRedemption::where('ticket_redeem_code_id', $lockedCode->id)
                ->where('user_id', $user->id)
                ->exists()) {
                return false;
            }

            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedUser->increment('ticket_balance', $lockedCode->ticket_amount);

            TicketLog::create([
                'user_id'     => $lockedUser->id,
                'type'        => 'credit',
                'amount'      => $lockedCode->ticket_amount,
                'source'      => 'redeem',
                'description' => $lockedCode->code,
            ]);

            TicketRedeemRedemption::create([
                'ticket_redeem_code_id' => $lockedCode->id,
                'user_id' => $lockedUser->id,
                'ticket_amount' => $lockedCode->ticket_amount,
                'redeemed_at' => now(),
            ]);

            $lockedCode->increment('used_count');

            return $lockedUser->fresh()->ticket_balance;
        });

        if ($ticketBalance === null) {
            return response()->json([
                'message' => 'Kuota voucher sudah habis',
                'error_code' => 'quota_exhausted',
            ], 422);
        }

        if ($ticketBalance === false) {
            return response()->json([
                'message' => 'Voucher sudah terpakai',
                'error_code' => 'already_used',
            ], 422);
        }

        return response()->json([
            'message' => 'Voucher berhasil digunakan',
            'data' => [
                'type' => 'ticket',
                'ticket_amount' => $redeemCode->ticket_amount,
                'ticket_balance' => $ticketBalance,
            ],
        ]);
    }
}
