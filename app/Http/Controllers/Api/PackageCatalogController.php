<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;

class PackageCatalogController extends Controller
{
    /**
     * One catalogue for both tracks.
     *
     * A ticket has never carried a kategori: users.ticket_balance is a single
     * integer and the debit in UserTryoutController does not look at the track,
     * so a ticket bought from any package already works on any tryout the user
     * can open. Filtering the catalogue by kategori only hid packages while the
     * tickets behind them were interchangeable, which made the store look
     * split when the currency was not.
     *
     * packages.kategori is therefore no longer read here. Tryouts and kelas
     * stay filtered by kategori - the content is genuinely per-track; the
     * ticket that unlocks it is not.
     */
    public function index(): JsonResponse
    {
        $packages = Package::where('is_active', true)
            ->orderBy('price', 'asc')
            ->get();

        return response()->json([
            'data' => $packages,
        ]);
    }

    public function show(Package $package): JsonResponse
    {
        if (!$package->is_active) {
            return response()->json([
                'message' => 'Paket tidak tersedia'
            ], 404);
        }

        return response()->json([
            'data' => $package,
        ]);
    }
}
