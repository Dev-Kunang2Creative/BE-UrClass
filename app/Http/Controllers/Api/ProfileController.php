<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function updateKategori(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kategori' => ['required', 'in:utbk,cpns'],
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json([
            'message' => 'Kategori berhasil disimpan',
            'user' => $user->fresh(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'], 
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^[0-9\+\-\s]+$/'],
            'birth_date' => ['required', 'date'],
            'gender' => ['required', 'in:L,P'],
            
            'school_origin' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'grade_level' => ['required', 'string', 'max:50', 'regex:/^[^\<\>]+$/u'],
            
            'target_university_1' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_major_1' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_university_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_major_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
        ], [
            'name.regex' => 'Nama tidak boleh mengandung tag HTML atau karakter script.',
            'phone_number.regex' => 'Format nomor telepon tidak valid.',
            'school_origin.regex' => 'Asal sekolah tidak boleh mengandung tag HTML.',
            'grade_level.regex' => 'Kelas tidak boleh mengandung tag HTML.',
            'target_university_1.regex' => 'Pilihan universitas tidak boleh mengandung tag HTML.',
            'target_major_1.regex' => 'Pilihan jurusan tidak boleh mengandung tag HTML.',
            'target_university_2.regex' => 'Pilihan universitas tidak boleh mengandung tag HTML.',
            'target_major_2.regex' => 'Pilihan jurusan tidak boleh mengandung tag HTML.',
        ]);

        $sanitized = array_map(function ($value) {
            return is_string($value) ? strip_tags(trim($value)) : $value;
        }, $validated);

        $user->update($sanitized);

        return response()->json([
            'message' => 'Profil berhasil dilengkapi',
            'user' => $user->fresh(),
        ]);
    }
}