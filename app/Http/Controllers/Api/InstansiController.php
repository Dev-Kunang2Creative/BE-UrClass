<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formasi;
use App\Models\Instansi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Referensi instansi dan formasi untuk target pelamar CPNS umum.
 *
 * Bentuknya menyalin PerguruanTinggiController dengan sengaja: dua tingkat
 * pilihan, dicari lewat teks, dan tingkat kedua bisa dipersempit oleh yang
 * pertama. Dengan begitu picker yang sama di frontend bisa dipakai untuk
 * keduanya tanpa komponen baru.
 *
 * Data diisi lewat seeder dari berkas resmi BKN, bukan lewat API ini.
 */
class InstansiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'tingkat' => ['nullable', 'string', 'in:pusat,daerah'],
        ]);

        $query = Instansi::query()
            ->select(['id', 'kode', 'nama', 'tingkat'])
            ->active()
            ->withCount('formasi')
            ->orderBy('nama');

        if ($tingkat = $validated['tingkat'] ?? null) {
            $query->where('tingkat', $tingkat);
        }

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        return response()->json($query->paginate($validated['per_page'] ?? 50));
    }

    /** Formasi milik satu instansi. */
    public function formasi(Request $request, Instansi $instansi): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $instansi->formasi()->getQuery()
            ->select(['id', 'instansi_id', 'nama', 'jenjang'])
            ->active()
            ->orderBy('nama');

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        return response()->json($query->paginate($validated['per_page'] ?? 50));
    }

    /**
     * Pencarian formasi lintas instansi.
     *
     * Dipakai ketika instansinya belum dipilih, supaya peserta yang hanya tahu
     * nama jabatannya tetap bisa mulai dari sana.
     */
    public function searchFormasi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Formasi::query()
            ->with('instansi:id,nama')
            ->select(['id', 'instansi_id', 'nama', 'jenjang'])
            ->active()
            ->orderBy('nama');

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        return response()->json($query->paginate($validated['per_page'] ?? 50));
    }
}
