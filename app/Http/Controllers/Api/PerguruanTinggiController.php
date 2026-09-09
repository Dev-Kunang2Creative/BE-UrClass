<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PerguruanTinggi;
use App\Models\ProgramStudi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerguruanTinggiController extends Controller
{
    /**
     * Reference data for the "target campus" pickers. Read-only: it is
     * refreshed by reseeding, never through the API.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Sekolah kedinasan berada di tabel yang sama, dibedakan kolom ini.
            // Tanpa filter, keduanya ikut terbawa - dan peserta UTBK tidak
            // seharusnya menemukan IPDN di daftar target kampusnya.
            'jenis' => ['nullable', 'string', 'in:ptn,kedinasan'],
        ]);

        $query = PerguruanTinggi::query()
            ->select(['id', 'kode_ptn', 'nama', 'jenis'])
            ->jenis($validated['jenis'] ?? null)
            ->withCount('programStudi')
            ->orderBy('nama');

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        return response()->json(
            $query->paginate($validated['per_page'] ?? 50)
        );
    }

    public function show(PerguruanTinggi $perguruanTinggi): JsonResponse
    {
        return response()->json([
            'data' => $perguruanTinggi->loadCount('programStudi'),
        ]);
    }

    /**
     * Programmes offered by one university.
     */
    public function programStudi(Request $request, PerguruanTinggi $perguruanTinggi): JsonResponse
    {
        $validated = $request->validate([
            'jenjang' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = $perguruanTinggi->programStudi()->getQuery();

        if ($jenjang = $validated['jenjang'] ?? null) {
            $query->where('jenjang', $jenjang);
        }

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        return response()->json([
            'data' => $query->orderBy('nama')->get(),
        ]);
    }

    /**
     * Search programmes across every university.
     */
    public function searchProgramStudi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'jenjang' => ['nullable', 'string', 'max:32'],
            'perguruan_tinggi_id' => ['nullable', 'ulid', 'exists:perguruan_tinggi,id'],
            // Sorting by keketatan cannot happen in PHP: it is an accessor, so
            // ordering it after pagination would only sort the current page.
            // Done in SQL instead, and rows with no published quota are pushed
            // last rather than treated as least competitive.
            'sort' => ['nullable', 'in:nama,daya_tampung,peminat,keketatan'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ProgramStudi::query()
            ->with('perguruanTinggi:id,kode_ptn,nama');

        if ($search = $validated['search'] ?? null) {
            $query->where('nama', 'like', '%'.$search.'%');
        }

        if ($jenjang = $validated['jenjang'] ?? null) {
            $query->where('jenjang', $jenjang);
        }

        if ($ptId = $validated['perguruan_tinggi_id'] ?? null) {
            $query->where('perguruan_tinggi_id', $ptId);
        }

        match ($validated['sort'] ?? 'nama') {
            'daya_tampung' => $query->orderByRaw('daya_tampung IS NULL, daya_tampung DESC'),
            'peminat' => $query->orderByRaw('peminat IS NULL, peminat DESC'),
            'keketatan' => $query->orderByRaw(
                'daya_tampung IS NULL OR daya_tampung = 0 OR peminat IS NULL,
                 (peminat / NULLIF(daya_tampung, 0)) DESC'
            ),
            default => $query->orderBy('nama'),
        };

        return response()->json(
            $query->paginate($validated['per_page'] ?? 50)
        );
    }

    /**
     * The distinct jenjang values actually present, so the frontend can build
     * its filter from the data instead of hardcoding a list that drifts.
     */
    public function jenjang(): JsonResponse
    {
        return response()->json([
            'data' => ProgramStudi::query()
                ->select('jenjang')
                ->distinct()
                ->orderBy('jenjang')
                ->pluck('jenjang'),
        ]);
    }
}
