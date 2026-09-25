<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackCard;
use App\Services\AuditLogger;
use App\Support\AturanMasukan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrackCardController extends Controller
{
    /** Dibaca halaman pemilihan jalur. Terbuka: isinya memang teks publik. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => TrackCard::semua()]);
    }

    /** Panel admin membaca daftar yang sama, lewat rute yang terjaga. */
    public function adminIndex(): JsonResponse
    {
        return response()->json(['data' => TrackCard::semua()]);
    }

    public function update(Request $request, string $kategori): JsonResponse
    {
        abort_unless(in_array($kategori, TrackCard::KATEGORI, true), 404);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:80', 'regex:'.AturanMasukan::TEKS_PENDEK],
            'badge' => ['nullable', 'string', 'max:40', 'regex:'.AturanMasukan::TEKS_PENDEK],
            'cta' => ['nullable', 'string', 'max:60', 'regex:'.AturanMasukan::TEKS_PENDEK],
            'description' => ['nullable', 'string', 'max:300'],
            // Tiga poin adalah yang muat di kartunya. Lebih dari itu akan
            // memanjangkan satu kartu saja dan membuat keduanya tidak sejajar.
            'features' => ['nullable', 'array', 'max:3'],
            'features.*' => ['required', 'string', 'max:80', 'regex:'.AturanMasukan::TEKS_PENDEK],
        ], [
            'features.max' => 'Maksimal 3 poin keunggulan per kartu.',
            'title.regex' => 'Judul mengandung karakter yang tidak diperbolehkan.',
            'badge.regex' => 'Label mengandung karakter yang tidak diperbolehkan.',
            'cta.regex' => 'Teks tombol mengandung karakter yang tidak diperbolehkan.',
            'features.*.regex' => 'Poin keunggulan mengandung karakter yang tidak diperbolehkan.',
        ]);

        $bersih = array_map(
            fn ($nilai) => is_string($nilai) ? strip_tags(trim($nilai)) : $nilai,
            $validated,
        );

        if (isset($bersih['features'])) {
            $bersih['features'] = array_values(array_filter(
                array_map(fn ($poin) => strip_tags(trim((string) $poin)), $bersih['features']),
                fn ($poin) => $poin !== '',
            ));
        }

        TrackCard::updateOrCreate(['kategori' => $kategori], $bersih);

        AuditLogger::log('TrackCard', 'update',
            "Kartu jalur {$kategori} diubah", $request->user());

        return response()->json([
            'message' => 'Kartu jalur berhasil disimpan',
            'data' => TrackCard::untuk($kategori),
        ]);
    }

    /** Mengembalikan satu kartu ke teks bawaannya. */
    public function reset(Request $request, string $kategori): JsonResponse
    {
        abort_unless(in_array($kategori, TrackCard::KATEGORI, true), 404);

        TrackCard::where('kategori', $kategori)->delete();

        AuditLogger::log('TrackCard', 'reset',
            "Kartu jalur {$kategori} dikembalikan ke teks bawaan", $request->user());

        return response()->json([
            'message' => 'Kartu jalur dikembalikan ke teks bawaan',
            'data' => TrackCard::untuk($kategori),
        ]);
    }
}
