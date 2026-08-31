<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProofRequirement;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Syarat bukti pendaftaran tryout gratis.
 *
 * index() dipakai halaman pendaftaran peserta, sisanya panel admin. Keduanya
 * membaca sumber yang sama, jadi menambah atau mengubah syarat cukup dilakukan
 * sekali dan langsung ikut mengubah apa yang diminta saat mendaftar - tidak ada
 * angka atau teks yang perlu disamakan di tempat lain.
 */
class ProofRequirementController extends Controller
{
    /** Syarat aktif, untuk ditampilkan ke peserta. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ProofRequirement::active()->get(),
        ]);
    }

    /** Semua syarat termasuk yang nonaktif, untuk panel admin. */
    public function adminIndex(): JsonResponse
    {
        return response()->json([
            'data' => ProofRequirement::orderBy('order_no')->orderBy('title')->get(),
            'meta' => ['icons' => ProofRequirement::ICONS],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        // Syarat baru diletakkan di akhir kalau urutannya tidak ditentukan,
        // bukan di posisi 0 - kalau tidak, setiap tambahan menyelip ke depan.
        if (! array_key_exists('order_no', $validated) || $validated['order_no'] === null) {
            $validated['order_no'] = (int) ProofRequirement::max('order_no') + 1;
        }

        $requirement = ProofRequirement::create($validated);

        AuditLogger::log(
            'ProofRequirement', 'create',
            "Syarat bukti ditambahkan: {$requirement->title}",
            $request->user(), $requirement
        );

        return response()->json([
            'message' => 'Syarat bukti berhasil ditambahkan.',
            'data' => $requirement,
        ], 201);
    }

    public function update(Request $request, ProofRequirement $proofRequirement): JsonResponse
    {
        $validated = $this->validatePayload($request, $proofRequirement->id);

        // Menonaktifkan syarat terakhir sama saja dengan membuka tryout gratis
        // tanpa bukti apa pun - dan itu keputusan yang tidak boleh terjadi
        // sebagai efek samping menyunting satu baris.
        if (
            array_key_exists('is_active', $validated)
            && $validated['is_active'] === false
            && $proofRequirement->is_active
            && ProofRequirement::active()->count() <= 1
        ) {
            return response()->json([
                'message' => 'Minimal satu syarat harus tetap aktif, kalau tidak pendaftaran tryout gratis berjalan tanpa bukti apa pun.',
            ], 422);
        }

        $proofRequirement->update($validated);

        AuditLogger::log(
            'ProofRequirement', 'update',
            "Syarat bukti diubah: {$proofRequirement->title}",
            $request->user(), $proofRequirement
        );

        return response()->json([
            'message' => 'Syarat bukti berhasil diperbarui.',
            'data' => $proofRequirement->fresh(),
        ]);
    }

    public function destroy(Request $request, ProofRequirement $proofRequirement): JsonResponse
    {
        if (ProofRequirement::active()->count() <= 1 && $proofRequirement->is_active) {
            return response()->json([
                'message' => 'Minimal satu syarat harus tetap aktif, kalau tidak pendaftaran tryout gratis berjalan tanpa bukti apa pun.',
            ], 422);
        }

        AuditLogger::log(
            'ProofRequirement', 'delete',
            "Syarat bukti dihapus: {$proofRequirement->title}",
            $request->user()
        );

        $proofRequirement->delete();

        return response()->json(['message' => 'Syarat bukti berhasil dihapus.']);
    }

    /**
     * Urutan ditetapkan sekaligus, bukan satu per satu.
     *
     * Menggeser satu syarat selalu mengubah posisi syarat lain, jadi mengirimnya
     * satu-satu berarti melewati keadaan di mana dua baris punya urutan sama -
     * dan peserta yang memuat halaman tepat pada saat itu melihat urutan yang
     * salah.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string', Rule::exists('proof_requirements', 'id')],
        ], [
            'ids.required' => 'Kirim daftar id sesuai urutan yang diinginkan.',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['ids'] as $index => $id) {
                ProofRequirement::whereKey($id)->update(['order_no' => $index + 1]);
            }
        });

        AuditLogger::log(
            'ProofRequirement', 'reorder',
            'Urutan syarat bukti diubah',
            $request->user()
        );

        return response()->json([
            'message' => 'Urutan syarat berhasil disimpan.',
            'data' => ProofRequirement::orderBy('order_no')->orderBy('title')->get(),
        ]);
    }

    private function validatePayload(Request $request, ?string $ignoreId = null): array
    {
        $icon = $request->input('icon');

        // Tautan dibakukan sebelum divalidasi supaya admin boleh menulis
        // "@urclass" untuk syarat Instagram tanpa perlu tahu bentuk URL-nya.
        $request->merge([
            'link_url' => ProofRequirement::normaliseLink($request->input('link_url'), $icon),
        ]);

        $validated = $request->validate([
            'title' => [
                'required', 'string', 'max:120', 'regex:/^[^\<\>]+$/u',
                Rule::unique('proof_requirements', 'title')->ignore($ignoreId),
            ],
            'instruction' => ['nullable', 'string', 'max:500', 'regex:/^[^\<\>]+$/u'],
            'link_url' => ['nullable', 'url', 'max:255'],
            'link_label' => ['nullable', 'string', 'max:60', 'regex:/^[^\<\>]+$/u'],
            'icon' => ['nullable', 'string', Rule::in(ProofRequirement::ICONS)],
            'order_no' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'title.required' => 'Judul syarat wajib diisi.',
            'title.unique' => 'Sudah ada syarat dengan judul itu.',
            'title.regex' => 'Judul tidak boleh mengandung tag HTML.',
            'instruction.regex' => 'Instruksi tidak boleh mengandung tag HTML.',
            'instruction.max' => 'Instruksi maksimal 500 karakter.',
            'link_url.url' => 'Tautan harus berupa URL yang valid, atau kosongkan kalau syarat ini tidak butuh tautan.',
            'icon.in' => 'Ikon tidak dikenali.',
        ]);

        // Tautan tanpa label tetap perlu teks tombol; URL mentah terlalu panjang
        // untuk dijadikan label.
        if (! empty($validated['link_url']) && empty($validated['link_label'])) {
            $validated['link_label'] = 'Buka tautan';
        }

        $validated['is_active'] = $validated['is_active'] ?? true;

        return $validated;
    }
}
