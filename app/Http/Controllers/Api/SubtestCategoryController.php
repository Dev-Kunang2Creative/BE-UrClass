<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubtestCategory;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubtestCategoryController extends Controller
{
    /**
     * Endpoint untuk user/form: hanya kategori aktif.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = SubtestCategory::active()
            ->when($request->filled('exam_type'), function ($query) use ($request) {
                $query->where('exam_type', strtolower($request->query('exam_type')));
            })
            ->orderBy('exam_type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    /**
     * Endpoint admin: daftar semua kategori termasuk yang nonaktif.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $categories = SubtestCategory::query()
            ->when($request->filled('exam_type'), function ($query) use ($request) {
                $query->where('exam_type', strtolower($request->query('exam_type')));
            })
            ->orderBy('exam_type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:subtest_categories,code'],
            'name' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', 'in:utbk,cpns'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        $category = SubtestCategory::create($validated);
        AuditLogger::log('SubtestCategory', 'create', "Kategori subtes dibuat: \"{$category->name}\" ({$category->exam_type})", $request->user(), $category);

        return response()->json([
            'message' => 'Kategori subtes berhasil dibuat',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, SubtestCategory $subtestCategory): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:subtest_categories,code,' . $subtestCategory->id],
            'name' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', 'in:utbk,cpns'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $subtestCategory->update($validated);
        AuditLogger::log('SubtestCategory', 'update', "Kategori subtes diupdate: \"{$subtestCategory->name}\"", $request->user(), $subtestCategory);

        return response()->json([
            'message' => 'Kategori subtes berhasil diperbarui',
            'data' => $subtestCategory,
        ]);
    }

    public function toggleActive(Request $request, SubtestCategory $subtestCategory): JsonResponse
    {
        $subtestCategory->update([
            'is_active' => ! $subtestCategory->is_active,
        ]);

        $statusText = $subtestCategory->is_active ? 'diaktifkan' : 'dinonaktifkan';
        AuditLogger::log('SubtestCategory', 'update', "Kategori subtes {$statusText}: \"{$subtestCategory->name}\"", $request->user(), $subtestCategory);

        return response()->json([
            'message' => "Kategori subtes berhasil {$statusText}",
            'data' => $subtestCategory,
        ]);
    }

    public function destroy(Request $request, SubtestCategory $subtestCategory): JsonResponse
    {
        AuditLogger::log('SubtestCategory', 'delete', "Kategori subtes dihapus: \"{$subtestCategory->name}\"", $request->user());
        $subtestCategory->delete();

        return response()->json([
            'message' => 'Kategori subtes berhasil dihapus',
        ]);
    }
}
