<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Tryout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = Package::latest()->get();

        return response()->json([
            'data' => $packages,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'slug'           => ['nullable', 'string', 'max:255', 'unique:packages,slug'],
            'description'    => ['nullable', 'string'],
            'thumbnail'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'price'          => ['required', 'integer', 'min:0'],
            'discount_price' => ['nullable', 'integer', 'min:0', 'lt:price'],
            'ticket_amount'  => ['required', 'integer', 'min:1'],
            'currency'       => ['nullable', 'string', 'max:10'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')->store('packages/thumbnails', 'public');
        }

        $package = Package::create([
            'name'           => $validated['name'],
            'slug'           => $validated['slug'] ?? Str::slug($validated['name']),
            'description'    => $validated['description'] ?? null,
            'thumbnail'      => $thumbnailPath,
            'price'          => $validated['price'],
            'discount_price' => $validated['discount_price'] ?? null,
            'ticket_amount'  => $validated['ticket_amount'],
            'currency'       => $validated['currency'] ?? 'IDR',
            'is_active'      => $validated['is_active'] ?? true,
            'created_by'     => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Paket berhasil dibuat',
            'data'    => $package,
        ], 201);
    }

    public function show(Package $package): JsonResponse
    {
        return response()->json([
            'data' => $package,
        ]);
    }

    public function update(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'slug'           => ['nullable', 'string', 'max:255', 'unique:packages,slug,' . $package->id],
            'description'    => ['nullable', 'string'],
            'thumbnail'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'price'          => ['required', 'integer', 'min:0'],
            'discount_price' => ['nullable', 'integer', 'min:0', 'lt:price'],
            'ticket_amount'  => ['required', 'integer', 'min:1'],
            'currency'       => ['nullable', 'string', 'max:10'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $thumbnailPath = $package->thumbnail;
        if ($request->hasFile('thumbnail')) {
            // Delete old thumbnail if exists
            if ($package->thumbnail) {
                Storage::disk('public')->delete($package->thumbnail);
            }
            $thumbnailPath = $request->file('thumbnail')->store('packages/thumbnails', 'public');
        }

        $package->update([
            'name'           => $validated['name'],
            'slug'           => $validated['slug'] ?? Str::slug($validated['name']),
            'description'    => $validated['description'] ?? null,
            'thumbnail'      => $thumbnailPath,
            'price'          => $validated['price'],
            'discount_price' => $validated['discount_price'] ?? null,
            'ticket_amount'  => $validated['ticket_amount'],
            'currency'       => $validated['currency'] ?? 'IDR',
            'is_active'      => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Paket berhasil diupdate',
            'data'    => $package,
        ]);
    }

    public function destroy(Package $package): JsonResponse
    {
        // Delete thumbnail if exists
        if ($package->thumbnail) {
            Storage::disk('public')->delete($package->thumbnail);
        }

        $package->delete();

        return response()->json([
            'message' => 'Paket berhasil dihapus',
        ]);
    }

    public function getTryouts(Package $package): JsonResponse
    {
        $package->load('tryouts');

        return response()->json([
            'data' => $package->tryouts,
        ]);
    }

    public function attachTryout(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'tryout_id' => ['required', 'string', 'exists:tryouts,id'],
        ]);

        if ($package->tryouts()->where('tryout_id', $validated['tryout_id'])->exists()) {
            return response()->json(['message' => 'Try out sudah ada di paket ini.'], 422);
        }

        $package->tryouts()->attach($validated['tryout_id']);

        return response()->json(['message' => 'Try out berhasil ditambahkan ke paket.'], 201);
    }

    public function detachTryout(Package $package, Tryout $tryout): JsonResponse
    {
        $package->tryouts()->detach($tryout->id);

        return response()->json(['message' => 'Try out berhasil dihapus dari paket.']);
    }
}