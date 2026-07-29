<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminKelasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 15);
        $search  = $request->string('search', '');

        $kelas = Kelas::withCount('enrollments')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->latest()
            ->paginate($perPage);

        return response()->json($kelas);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->discount_price === '') {
            $request->merge(['discount_price' => null]);
        }

        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'description'            => ['nullable', 'string'],
            'price'                  => ['required', 'integer', 'min:0'],
            'discount_price'         => ['nullable', 'integer', 'min:1', function ($attr, $val, $fail) use ($request) {
                if ($val !== null && $val >= (int) $request->price) {
                    $fail('Harga diskon harus lebih rendah dari harga asli.');
                }
            }],
            'ticket_amount'          => ['nullable', 'integer', 'min:0'],
            'wa_group_link'          => ['nullable', 'string', 'max:255'],
            'wa_consultation_number' => ['nullable', 'string', 'max:50'],
            'meet_link'              => ['nullable', 'string', 'max:255'],
            'image'                  => ['nullable', 'image', 'max:2048'],
            'is_active'              => ['nullable', 'boolean'],
        ]);

        $validated['slug']          = Str::slug($validated['name']) . '-' . Str::lower(Str::random(6));
        $validated['ticket_amount'] = $validated['ticket_amount'] ?? 0;
        $validated['is_active']     = $validated['is_active'] ?? true;
        $validated['created_by']    = $request->user()->id;

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('kelas-images', 'public');
        }

        $kelas = Kelas::create($validated);

        AuditLogger::log(
            'Kelas',
            'create',
            "Kelas dibuat: {$kelas->name}",
            $request->user(),
            $kelas
        );

        return response()->json([
            'message' => 'Kelas berhasil dibuat',
            'data'    => $kelas,
        ], 201);
    }

    public function show(Kelas $kelas): JsonResponse
    {
        return response()->json([
            'data' => $kelas,
        ]);
    }

    public function update(Request $request, Kelas $kelas): JsonResponse
    {
        if ($request->discount_price === '') {
            $request->merge(['discount_price' => null]);
        }

        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'description'            => ['nullable', 'string'],
            'price'                  => ['required', 'integer', 'min:0'],
            'discount_price'         => ['nullable', 'integer', 'min:1', function ($attr, $val, $fail) use ($request) {
                if ($val !== null && $val >= (int) $request->price) {
                    $fail('Harga diskon harus lebih rendah dari harga asli.');
                }
            }],
            'ticket_amount'          => ['nullable', 'integer', 'min:0'],
            'wa_group_link'          => ['nullable', 'string', 'max:255'],
            'wa_consultation_number' => ['nullable', 'string', 'max:50'],
            'meet_link'              => ['nullable', 'string', 'max:255'],
            'image'                  => ['nullable', 'image', 'max:2048'],
            'is_active'              => ['nullable', 'boolean'],
        ]);

        // Keep existing slug unless name has changed
        if ($validated['name'] !== $kelas->name) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . Str::lower(Str::random(6));
        }

        $validated['ticket_amount']  = $validated['ticket_amount'] ?? 0;
        $validated['is_active']      = $validated['is_active'] ?? $kelas->is_active;
        $validated['discount_price'] = array_key_exists('discount_price', $validated) ? $validated['discount_price'] : null;

        if ($request->hasFile('image')) {
            // Delete old image
            if ($kelas->image) {
                Storage::disk('public')->delete($kelas->image);
            }
            $validated['image'] = $request->file('image')->store('kelas-images', 'public');
        }

        $kelas->update($validated);

        AuditLogger::log(
            'Kelas',
            'update',
            "Kelas diupdate: {$kelas->name}",
            $request->user(),
            $kelas
        );

        return response()->json([
            'message' => 'Kelas berhasil diupdate',
            'data'    => $kelas,
        ]);
    }

    public function destroy(Request $request, Kelas $kelas): JsonResponse
    {
        if ($kelas->image) {
            Storage::disk('public')->delete($kelas->image);
        }

        AuditLogger::log(
            'Kelas',
            'delete',
            "Kelas dihapus: {$kelas->name}",
            $request->user(),
            $kelas
        );

        $kelas->delete();

        return response()->json([
            'message' => 'Kelas berhasil dihapus',
        ]);
    }
}
