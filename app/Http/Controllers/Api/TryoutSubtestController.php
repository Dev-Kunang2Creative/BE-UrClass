<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\TryoutSubtest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TryoutSubtestController extends Controller
{
    public function index(Tryout $tryout): JsonResponse
    {
        $items = TryoutSubtest::with('subtest')
            ->where('tryout_id', $tryout->id)
            ->inRandomOrder() 
            ->get();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function store(Request $request, Tryout $tryout): JsonResponse
    {
        $validated = $request->validate([
            'subtests' => ['required', 'array'],
            'subtests.*.subtest_id' => ['required', 'exists:subtests,id'],
            'subtests.*.duration_minutes' => ['required', 'integer', 'min:1'],
            'subtests.*.is_active' => ['nullable', 'boolean'],
        ]);

        $attachedItems = [];

        foreach ($validated['subtests'] as $subtestData) {
            $exists = TryoutSubtest::where('tryout_id', $tryout->id)
                ->where('subtest_id', $subtestData['subtest_id'])
                ->exists();

            if (!$exists) {
                $item = TryoutSubtest::create([
                    'tryout_id' => $tryout->id,
                    'subtest_id' => $subtestData['subtest_id'],
                    'duration_minutes' => $subtestData['duration_minutes'],
                    'is_active' => $subtestData['is_active'] ?? true,
                ]);

                $attachedItems[] = $item->load('subtest');
            }
        }

        return response()->json([
            'message' => 'Subtest berhasil ditambahkan ke tryout',
            'data' => $attachedItems,
        ], 201);
    }

    public function update(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data subtest tryout tidak cocok',
            ], 404);
        }

        $validated = $request->validate([
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        $tryoutSubtest->update($validated);
        $tryoutSubtest->load('subtest');

        return response()->json([
            'message' => 'Pengaturan subtest tryout berhasil diupdate',
            'data' => $tryoutSubtest,
        ]);
    }

    public function destroy(Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data subtest tryout tidak cocok',
            ], 404);
        }

        $tryoutSubtest->delete();

        return response()->json([
            'message' => 'Subtest berhasil dihapus dari tryout',
        ]);
    }
}