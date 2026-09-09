<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formasi;
use App\Models\Instansi;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD instansi dan formasi untuk panel admin.
 *
 * Seeder CSV mengisi borongan dari rekap resmi BKN, tapi rekap formasi itu tidak
 * tersedia dalam bentuk yang bisa diunduh - jadi tanpa layar ini tidak ada cara
 * sama sekali menambahkan formasi selain menyunting berkas lalu menjalankan
 * seeder di server. Keduanya hidup berdampingan: seeder untuk borongan tahunan,
 * layar ini untuk menambah dan memperbaiki satuan.
 */
class AdminInstansiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Instansi::query()
            ->withCount('formasi')
            ->orderBy('nama');

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        return response()->json($query->paginate($validated['per_page'] ?? 50));
    }

    public function storeInstansi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'kode' => ['nullable', 'string', 'max:32', Rule::unique('instansi', 'kode')],
            'tingkat' => ['required', 'in:pusat,daerah'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $instansi = Instansi::create($validated + ['is_active' => $validated['is_active'] ?? true]);
        AuditLogger::log(
            'Instansi', 'create',
            "Instansi ditambahkan: {$instansi->nama}",
            $request->user(), $instansi
        );

        return response()->json([
            'message' => 'Instansi berhasil ditambahkan.',
            'data' => $instansi->loadCount('formasi'),
        ], 201);
    }

    public function updateInstansi(Request $request, Instansi $instansi): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'kode' => ['nullable', 'string', 'max:32', Rule::unique('instansi', 'kode')->ignore($instansi->id)],
            'tingkat' => ['required', 'in:pusat,daerah'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $instansi->update($validated);
        AuditLogger::log(
            'Instansi', 'update',
            "Instansi diubah: {$instansi->nama}",
            $request->user(), $instansi
        );

        return response()->json([
            'message' => 'Instansi berhasil diperbarui.',
            'data' => $instansi->fresh()->loadCount('formasi'),
        ]);
    }

    public function destroyInstansi(Request $request, Instansi $instansi): JsonResponse
    {
        AuditLogger::log(
            'Instansi', 'delete',
            "Instansi dihapus: {$instansi->nama} (beserta {$instansi->formasi()->count()} formasi)",
            $request->user()
        );
        $instansi->delete();

        return response()->json(['message' => 'Instansi berhasil dihapus.']);
    }

    /** Formasi milik satu instansi, termasuk yang nonaktif. */
    public function formasi(Request $request, Instansi $instansi): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = $instansi->formasi()->getQuery()->orderBy('nama');

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        return response()->json(['data' => $query->get()]);
    }

    public function storeFormasi(Request $request, Instansi $instansi): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u',
                Rule::unique('formasi', 'nama')->where('instansi_id', $instansi->id)],
            'jenjang' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'nama.unique' => 'Formasi dengan nama itu sudah ada di instansi ini.',
        ]);

        $formasi = $instansi->formasi()->create($validated + ['is_active' => $validated['is_active'] ?? true]);
        AuditLogger::log(
            'Formasi', 'create',
            "Formasi ditambahkan: {$formasi->nama} ({$instansi->nama})",
            $request->user(), $formasi
        );

        return response()->json([
            'message' => 'Formasi berhasil ditambahkan.',
            'data' => $formasi,
        ], 201);
    }

    public function destroyFormasi(Request $request, Instansi $instansi, Formasi $formasi): JsonResponse
    {
        if ($formasi->instansi_id !== $instansi->id) {
            return response()->json(['message' => 'Formasi tidak ditemukan pada instansi ini.'], 404);
        }

        AuditLogger::log(
            'Formasi', 'delete',
            "Formasi dihapus: {$formasi->nama} ({$instansi->nama})",
            $request->user()
        );
        $formasi->delete();

        return response()->json(['message' => 'Formasi berhasil dihapus.']);
    }
}
