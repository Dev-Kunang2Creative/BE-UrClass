<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\Tryout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAccessCodeController extends Controller
{
    public function index(Tryout $tryout): JsonResponse
    {
        $codes = AccessCode::where('tryout_id', $tryout->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $codes,
        ]);
    }

    public function store(Request $request, Tryout $tryout): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:255', 'unique:access_codes,code'],
            'max_usage' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'expired_at' => ['nullable', 'date'],
        ]);

        $accessCode = AccessCode::create([
            'code' => $validated['code'] ?? strtoupper(Str::random(10)),
            'tryout_id' => $tryout->id,
            'max_usage' => $validated['max_usage'] ?? 1,
            'used_count' => 0,
            'is_active' => $validated['is_active'] ?? true,
            'expired_at' => $validated['expired_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Access code berhasil dibuat',
            'data' => $accessCode,
        ], 201);
    }

    public function show(Tryout $tryout, AccessCode $accessCode): JsonResponse
    {
        if ($accessCode->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data access code tidak cocok',
            ], 404);
        }

        return response()->json([
            'data' => $accessCode,
        ]);
    }

    public function update(Request $request, Tryout $tryout, AccessCode $accessCode): JsonResponse
    {
        if ($accessCode->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data access code tidak cocok',
            ], 404);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', 'unique:access_codes,code,' . $accessCode->id],
            'max_usage' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'expired_at' => ['nullable', 'date'],
        ]);

        $accessCode->update([
            'code' => $validated['code'],
            'max_usage' => $validated['max_usage'],
            'is_active' => $validated['is_active'],
            'expired_at' => $validated['expired_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Access code berhasil diupdate',
            'data' => $accessCode,
        ]);
    }

    public function destroy(Tryout $tryout, AccessCode $accessCode): JsonResponse
    {
        if ($accessCode->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data access code tidak cocok',
            ], 404);
        }

        $accessCode->delete();

        return response()->json([
            'message' => 'Access code berhasil dihapus',
        ]);
    }
}