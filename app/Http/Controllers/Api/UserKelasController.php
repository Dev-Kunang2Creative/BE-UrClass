<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\UserKelasEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserKelasController extends Controller
{
    public function index(): JsonResponse
    {
        $kelas = Kelas::where('is_active', true)
            ->withCount('enrollments')
            ->latest()
            ->get();

        return response()->json([
            'data' => $kelas,
        ]);
    }

    public function show(Kelas $kelas): JsonResponse
    {
        // Hide sensitive links from public show
        $data = $kelas->makeHidden(['wa_group_link', 'wa_consultation_number', 'meet_link']);
        $data->loadCount('enrollments');

        return response()->json([
            'data' => $data,
        ]);
    }

    public function myKelas(Request $request): JsonResponse
    {
        $enrollments = UserKelasEnrollment::with('kelas')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        // Full kelas data including links is returned via the enrollment relationship
        return response()->json([
            'data' => $enrollments,
        ]);
    }
}
