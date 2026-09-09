<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserTryoutAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminTryoutProofController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 12), 50);
        $search = trim((string) $request->query('search', ''));

        $proofs = UserTryoutAccess::with(['user:id,name,email', 'tryout:id,title,is_free'])
            ->where(function ($query) {
                $query->whereNotNull('proof_image')
                    ->orWhereNotNull('proof_images');
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('selection_status', $request->query('status'));
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })->orWhereHas('tryout', function ($tryoutQuery) use ($search) {
                        $tryoutQuery->where('title', 'like', "%{$search}%");
                    });
                });
            })
            ->latest('granted_at')
            ->paginate($perPage);

        $proofs->getCollection()->transform(function ($access) {
            $proofImages = collect($access->proof_images ?: ($access->proof_image ? [$access->proof_image] : []))
                ->filter()
                ->values();

            // Judul syarat per berkas, kalau pendaftarannya menyimpannya.
            // Pendaftaran lama tidak punya keterangan ini - urutannya satu-satunya
            // yang tersimpan - jadi antarmuka jatuh ke penomoran biasa untuk
            // baris itu, bukan menebak syarat mana yang berlaku saat itu.
            $titleByPath = collect($access->proof_details ?: [])
                ->filter(fn ($item) => ! empty($item['path']))
                ->mapWithKeys(fn ($item) => [$item['path'] => $item['title'] ?? null]);

            return [
                'id' => $access->id,
                'granted_at' => $access->granted_at,
                'user' => $access->user,
                'tryout' => $access->tryout,
                'selection_status' => $access->selection_status,
                'selection_status_label' => $access->selection_status_label,
                'selection_note' => $access->selection_note,
                'selection_reviewed_at' => $access->selection_reviewed_at,
                'proof_images' => $proofImages->all(),
                'proof_image_urls' => $proofImages
                    ->map(fn ($path) => asset(Storage::disk('public')->url($path)))
                    ->all(),
                'proof_items' => $proofImages
                    ->map(fn ($path) => [
                        'title' => $titleByPath[$path] ?? null,
                        'url' => asset(Storage::disk('public')->url($path)),
                    ])
                    ->all(),
            ];
        });

        return response()->json($proofs);
    }

    /**
     * BRD A-09: admin meninjau bukti dan mengubah status pengajuan seleksi gratis.
     * Alur: submitted -> under_review -> need_revision | accepted | rejected
     */
    public function review(Request $request, UserTryoutAccess $access): JsonResponse
    {
        $validated = $request->validate([
            'selection_status' => ['required', 'string', 'in:' . implode(',', UserTryoutAccess::SELECTION_STATUSES)],
            'selection_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $access->selection_status = $validated['selection_status'];
        $access->selection_note = $validated['selection_note'] ?? null;
        $access->selection_reviewed_at = now();
        $access->selection_reviewed_by = $request->user()?->id;

        // Akses gratis diberikan otomatis begitu pengajuan diterima,
        // dan dicabut kembali bila status berubah menjadi tidak diterima.
        if ($validated['selection_status'] === UserTryoutAccess::STATUS_ACCEPTED) {
            $access->granted_at = $access->granted_at ?: now();
        } elseif (in_array($validated['selection_status'], [
            UserTryoutAccess::STATUS_REJECTED,
            UserTryoutAccess::STATUS_NEED_REVISION,
        ], true)) {
            $access->granted_at = null;
        }

        $access->save();

        return response()->json([
            'message' => 'Status seleksi berhasil diperbarui',
            'data' => [
                'id' => $access->id,
                'selection_status' => $access->selection_status,
                'selection_status_label' => $access->selection_status_label,
                'selection_note' => $access->selection_note,
                'selection_reviewed_at' => $access->selection_reviewed_at,
                'granted_at' => $access->granted_at,
            ],
        ]);
    }

    /** Daftar status yang tersedia beserta labelnya untuk dropdown admin. */
    public function statuses(): JsonResponse
    {
        return response()->json([
            'data' => collect(UserTryoutAccess::STATUS_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }
}
