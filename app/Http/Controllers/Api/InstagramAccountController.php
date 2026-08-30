<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Daftar akun Instagram yang wajib di-follow untuk tryout gratis.
 *
 * index() dipakai halaman pendaftaran peserta, sisanya panel admin. Keduanya
 * membaca sumber yang sama, jadi menambah atau mengganti akun cukup dilakukan
 * sekali dan langsung ikut mengubah jumlah bukti yang diminta saat mendaftar.
 */
class InstagramAccountController extends Controller
{
    /** Akun aktif, untuk ditampilkan ke peserta. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => InstagramAccount::active()->get(),
        ]);
    }

    /** Semua akun termasuk yang nonaktif, untuk panel admin. */
    public function adminIndex(): JsonResponse
    {
        return response()->json([
            'data' => InstagramAccount::orderBy('order_no')->orderBy('username')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $account = InstagramAccount::create($validated);
        AuditLogger::log(
            'InstagramAccount', 'create',
            "Akun Instagram ditambahkan: @{$account->username}",
            $request->user(), $account
        );

        return response()->json([
            'message' => 'Akun Instagram berhasil ditambahkan.',
            'data' => $account,
        ], 201);
    }

    public function update(Request $request, InstagramAccount $instagramAccount): JsonResponse
    {
        $validated = $this->validatePayload($request, $instagramAccount->id);

        $instagramAccount->update($validated);
        AuditLogger::log(
            'InstagramAccount', 'update',
            "Akun Instagram diubah: @{$instagramAccount->username}",
            $request->user(), $instagramAccount
        );

        return response()->json([
            'message' => 'Akun Instagram berhasil diperbarui.',
            'data' => $instagramAccount->fresh(),
        ]);
    }

    public function destroy(Request $request, InstagramAccount $instagramAccount): JsonResponse
    {
        // Menonaktifkan akun terakhir akan membuat pendaftaran tryout gratis
        // meminta bukti untuk daftar akun yang kosong.
        if (InstagramAccount::active()->count() <= 1 && $instagramAccount->is_active) {
            return response()->json([
                'message' => 'Minimal satu akun Instagram harus tetap aktif selama tryout gratis masih membutuhkan bukti follow.',
            ], 422);
        }

        AuditLogger::log(
            'InstagramAccount', 'delete',
            "Akun Instagram dihapus: @{$instagramAccount->username}",
            $request->user()
        );
        $instagramAccount->delete();

        return response()->json(['message' => 'Akun Instagram berhasil dihapus.']);
    }

    private function validatePayload(Request $request, ?string $ignoreId = null): array
    {
        $request->merge([
            'username' => InstagramAccount::normaliseUsername((string) $request->input('username')),
        ]);

        $validated = $request->validate([
            // Aturan username Instagram: huruf, angka, titik, garis bawah.
            'username' => [
                'required', 'string', 'max:30', 'regex:/^[a-z0-9._]+$/',
                Rule::unique('instagram_accounts', 'username')->ignore($ignoreId),
            ],
            'label' => ['nullable', 'string', 'max:100'],
            'order_no' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'username.regex' => 'Username Instagram hanya boleh huruf, angka, titik, dan garis bawah.',
            'username.unique' => 'Akun Instagram ini sudah terdaftar.',
        ]);

        $validated['order_no'] = $validated['order_no'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;

        return $validated;
    }
}
