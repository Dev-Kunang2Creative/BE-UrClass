<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProviderException;
use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\AiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Kuota yang tersisa di provider AI.
 *
 * Diambil server, bukan browser - endpoint dan kunci tidak pernah keluar dari
 * sini, aturan yang sama dengan seluruh jalur AI lainnya.
 *
 * Ada karena kuota adalah satu-satunya angka penting yang **tidak** bisa
 * diketahui dari catatan aplikasi ini sendiri: pemakaian dari sumber lain -
 * pengujian manual, aplikasi lain yang memakai kunci yang sama - juga memotong
 * kuota, dan hanya provider yang tahu sisanya.
 */
class AdminAiQuotaController extends Controller
{
    public function show(Request $request, AiChatService $chat): JsonResponse
    {
        $setting = AiSetting::current();

        if (! filled($setting->endpoint) || ! filled($setting->api_key)) {
            return response()->json([
                'data' => [
                    'supported' => false,
                    'configured' => false,
                    'quota' => null,
                    'message' => 'Endpoint dan API key harus terisi dulu.',
                ],
            ]);
        }

        try {
            $hasil = $chat->fetchQuota($setting);
        } catch (AiProviderException $e) {
            return response()->json([
                'message' => $e->status()
                    ? "Provider menolak dengan status {$e->status()}."
                    : $e->getMessage(),
                'detail' => $e->detail($setting->api_key),
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Gagal membaca kuota: '.$e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'supported' => $hasil['supported'],
                'configured' => true,
                'quota' => $hasil['data'],
                'message' => $hasil['message'],
            ],
        ]);
    }
}
