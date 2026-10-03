<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoBanner;
use App\Services\AuditLogger;
use App\Support\AturanMasukan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PromoBannerController extends Controller
{
    /**
     * Banner untuk jalur peserta yang memintanya.
     *
     * Jalurnya diambil dari akun peserta, bukan dari parameter - kalau dari
     * parameter, banner jalur lain bisa dipanggil hanya dengan mengubah URL,
     * dan peserta CPNS akan melihat promo UTBK yang tidak berlaku baginya.
     */
    public function index(Request $request): JsonResponse
    {
        $banners = PromoBanner::query()
            ->aktif()
            ->untukJalur($request->user()?->kategori)
            ->orderBy('order_no')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $banners]);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json([
            'data' => PromoBanner::query()->orderBy('order_no')->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validasi($request, wajibGambar: true);
        $validated['image'] = $request->file('image')->store('promo-banners', 'public');

        $banner = PromoBanner::create($validated);
        AuditLogger::log('PromoBanner', 'create', "Banner promosi ditambahkan: \"{$banner->alt}\"", $request->user(), $banner);

        return response()->json(['message' => 'Banner berhasil ditambahkan', 'data' => $banner], 201);
    }

    public function update(Request $request, PromoBanner $promoBanner): JsonResponse
    {
        $validated = $this->validasi($request, wajibGambar: false);

        if ($request->hasFile('image')) {
            // Berkas lama dihapus setelah yang baru tersimpan, bukan sebelumnya:
            // kalau unggahannya gagal di tengah, banner-nya masih punya gambar.
            $lama = $promoBanner->image;
            $validated['image'] = $request->file('image')->store('promo-banners', 'public');

            if ($lama && ! filter_var($lama, FILTER_VALIDATE_URL)) {
                Storage::disk('public')->delete($lama);
            }
        }

        $promoBanner->update($validated);
        AuditLogger::log('PromoBanner', 'update', "Banner promosi diubah: \"{$promoBanner->alt}\"", $request->user(), $promoBanner);

        return response()->json(['message' => 'Banner berhasil disimpan', 'data' => $promoBanner->fresh()]);
    }

    public function destroy(Request $request, PromoBanner $promoBanner): JsonResponse
    {
        $gambar = $promoBanner->image;
        $nama = $promoBanner->alt;

        $promoBanner->delete();

        // Berkasnya tidak ikut terhapus oleh penghapusan baris.
        if ($gambar && ! filter_var($gambar, FILTER_VALIDATE_URL)) {
            Storage::disk('public')->delete($gambar);
        }

        AuditLogger::log('PromoBanner', 'delete', "Banner promosi dihapus: \"{$nama}\"", $request->user());

        return response()->json(['message' => 'Banner berhasil dihapus']);
    }

    /** @return array<string, mixed> */
    private function validasi(Request $request, bool $wajibGambar): array
    {
        return $request->validate([
            'image' => [$wajibGambar ? 'required' : 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            // alt dibacakan pembaca layar, jadi wajib - banner tanpa keterangan
            // adalah bagian halaman yang tidak ada sama sekali bagi sebagiannya.
            'alt' => ['required', 'string', 'max:150', 'regex:'.AturanMasukan::TEKS_PENDEK],
            // Hanya tujuan di dalam aplikasi, supaya banner tidak bisa dipakai
            // mengarahkan peserta ke luar.
            'href' => ['nullable', 'string', 'max:150', 'regex:/^\/[A-Za-z0-9\-\/_]*$/'],
            'kategori' => ['nullable', Rule::in(PromoBanner::KATEGORI)],
            'order_no' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'alt.required' => 'Keterangan gambar wajib diisi.',
            'href.regex' => 'Tautan harus berupa alamat di dalam aplikasi, diawali "/".',
        ]);
    }
}
