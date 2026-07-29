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

            return [
                'id' => $access->id,
                'granted_at' => $access->granted_at,
                'user' => $access->user,
                'tryout' => $access->tryout,
                'proof_images' => $proofImages->all(),
                'proof_image_urls' => $proofImages
                    ->map(fn ($path) => asset(Storage::disk('public')->url($path)))
                    ->all(),
            ];
        });

        return response()->json($proofs);
    }
}
