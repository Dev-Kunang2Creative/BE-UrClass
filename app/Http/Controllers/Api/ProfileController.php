<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'], 
            'phone_number' => ['required', 'string', 'max:20'],
            'birth_date' => ['required', 'date'],
            'gender' => ['required', 'in:L,P'],
            
            'school_origin' => ['required', 'string', 'max:255'],
            'grade_level' => ['required', 'string', 'max:50'],
            
            'target_university_1' => ['required', 'string', 'max:255'],
            'target_major_1' => ['required', 'string', 'max:255'],
            'target_university_2' => ['nullable', 'string', 'max:255'],
            'target_major_2' => ['nullable', 'string', 'max:255'],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profil berhasil dilengkapi',
            'user' => $user->fresh(),
        ]);
    }
}