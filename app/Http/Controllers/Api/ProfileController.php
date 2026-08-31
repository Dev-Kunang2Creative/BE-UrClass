<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formasi;
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

        // Admin tidak pernah mengikuti tryout, jadi tidak ada sertifikat maupun
        // laporan nilai yang perlu memakai data dirinya. Yang tersisa hanyalah
        // nama; sisanya diterima kalau dikirim, tapi tidak pernah diwajibkan.
        $isAdmin = ($user->role ?? 'user') === 'admin';

        $profileRequired = $isAdmin ? 'nullable' : 'required';

        $kategori = $user->kategori ?? 'utbk';
        $isUtbk = $kategori === 'utbk';
        $isCpns = $kategori === 'cpns';

        $targetRequired = $isAdmin || ! $isUtbk ? 'nullable' : 'required';

        // Peserta CPNS punya dua bentuk target, dan yang wajib diisi tergantung
        // sub-jalur yang ia pilih: pelamar sekolah kedinasan mengisi sekolah dan
        // program studi, pelamar CPNS umum mengisi instansi dan formasi. Meminta
        // keduanya berarti meminta salah satu diisi asal-asalan.
        $cpnsType = $request->input('cpns_target_type');
        $kedinasanRequired = ! $isAdmin && $isCpns && $cpnsType === 'kedinasan'
            ? 'required'
            : 'nullable';
        $umumRequired = ! $isAdmin && $isCpns && $cpnsType === 'umum'
            ? 'required'
            : 'nullable';

        // Formasi tidak bisa diwajibkan selama rekapnya belum terbit. Rinciannya
        // diumumkan SSCASN per periode seleksi, jadi ada masa di mana instansinya
        // sudah diketahui tetapi formasinya belum ada sama sekali - dan pada masa
        // itu mewajibkannya berarti tidak ada pelamar CPNS umum yang bisa
        // menyimpan profilnya.
        $formasiTersedia = Formasi::query()->active()->exists();
        $formasiRequired = $formasiTersedia ? $umumRequired : 'nullable';

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'], 
            'phone_number' => [$profileRequired, 'string', 'max:20', 'regex:/^[0-9\+\-\s]+$/'],
            'birth_date' => [$profileRequired, 'date'],
            'gender' => [$profileRequired, 'in:L,P'],
            
            'school_origin' => [$profileRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'grade_level' => [$profileRequired, 'string', 'max:50', 'regex:/^[^\<\>]+$/u'],

            // Previously absent from the rules, so they never reached
            // $validated and were never saved - the form asked for them and
            // threw the answers away.
            'province' => ['nullable', 'string', 'max:100', 'regex:/^[^\<\>]+$/u'],
            'city' => ['nullable', 'string', 'max:100', 'regex:/^[^\<\>]+$/u'],
            
            // A CPNS candidate has no target campus, and requiring one meant
            // they could not save a profile without inventing a university.
            // Sama halnya dengan admin, yang tidak punya target kampus apa pun.
            // Kolom yang sama menampung target PTN (UTBK) dan sekolah kedinasan
            // (CPNS): keduanya berbentuk sekolah plus program studi, jadi tidak
            // ada gunanya membuat pasangan kolom kedua yang isinya sejenis.
            'target_university_1' => [$isCpns ? $kedinasanRequired : $targetRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_major_1' => [$isCpns ? $kedinasanRequired : $targetRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_university_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_major_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],

            'cpns_target_type' => [$isCpns && ! $isAdmin ? 'required' : 'nullable', 'in:kedinasan,umum'],
            'target_instansi_1' => [$umumRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_formasi_1' => [$formasiRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_instansi_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_formasi_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
        ], [
            'name.regex' => 'Nama tidak boleh mengandung tag HTML atau karakter script.',
            'phone_number.regex' => 'Format nomor telepon tidak valid.',
            'school_origin.regex' => 'Asal sekolah tidak boleh mengandung tag HTML.',
            'grade_level.regex' => 'Kelas tidak boleh mengandung tag HTML.',
            'target_university_1.regex' => 'Pilihan universitas tidak boleh mengandung tag HTML.',
            'target_major_1.regex' => 'Pilihan jurusan tidak boleh mengandung tag HTML.',
            'target_university_2.regex' => 'Pilihan universitas tidak boleh mengandung tag HTML.',
            'target_major_2.regex' => 'Pilihan jurusan tidak boleh mengandung tag HTML.',
            'cpns_target_type.required' => 'Pilih dulu tujuanmu: sekolah kedinasan atau CPNS umum.',
            'target_instansi_1.required' => 'Instansi tujuan wajib diisi.',
            'target_formasi_1.required' => 'Formasi tujuan wajib diisi.',
            // Kolom yang sama menampung target PTN dan sekolah kedinasan, jadi
            // pesannya mengikuti jalur peserta - bukan nama kolomnya, yang tidak
            // berarti apa pun bagi yang membacanya.
            'target_university_1.required' => $isCpns
                ? 'Sekolah kedinasan tujuan wajib diisi.'
                : 'Universitas tujuan wajib diisi.',
            'target_major_1.required' => $isCpns
                ? 'Program studi tujuan wajib diisi.'
                : 'Jurusan tujuan wajib diisi.',
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