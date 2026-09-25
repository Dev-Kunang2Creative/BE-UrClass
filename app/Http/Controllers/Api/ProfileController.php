<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formasi;
use App\Support\AturanMasukan;
use App\Support\Jenjang;
use App\Support\NomorPonsel;
use App\Support\TargetKampus;
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
        $kategoriBaru = $validated['kategori'];

        // Target yang sah di jalur lama belum tentu sah di jalur baru. Peserta
        // CPNS sub-jalur kedinasan yang pindah ke UTBK akan membawa serta
        // "IPDN" sebagai universitas pilihannya, dan nilai itu lolos setiap
        // penyimpanan profil berikutnya karena tidak ada yang mengubahnya lagi.
        //
        // Yang dikosongkan hanya yang benar-benar bertentangan: target yang
        // masih masuk akal di jalur baru dipertahankan, supaya pindah jalur
        // tidak menghapus isian yang tidak perlu diisi ulang.
        $dibersihkan = [];

        foreach (['target_university_1', 'target_university_2'] as $kolom) {
            if (TargetKampus::bertentangan($user->{$kolom}, $kategoriBaru)) {
                $dibersihkan[$kolom] = null;
                $dibersihkan[str_replace('university', 'major', $kolom)] = null;
            }
        }

        // cpns_target_type sengaja TIDAK dikosongkan saat pindah ke UTBK.
        //
        // Dulu dikosongkan dengan alasan "sub-jalur milik CPNS saja", dan itu
        // menimbulkan bug: kembali ke CPNS, sub-jalurnya kosong sehingga form
        // jatuh ke "kedinasan" - dan pilihan itu menyembunyikan seluruh bagian
        // instansi & formasi. Peserta melihat instansi yang sudah diisinya
        // lenyap, padahal barisnya masih utuh di database.
        //
        // Nilainya memang tidak dipakai selama peserta berada di UTBK, tapi
        // tidak dipakai bukan berarti salah. Yang boleh dibersihkan hanya yang
        // bertentangan dengan jalur barunya, seperti target kampus di atas.

        $user->update($validated + $dibersihkan);

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
        // sub-jalur yang ia pilih: pelamar sekolah kedinasan mengisi sekolah,
        // pelamar CPNS umum mengisi instansi dan formasi. Meminta
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

        // Daftar kampus yang disaring menurut jenis hanya membentuk isi dropdown;
        // kolomnya sendiri menerima ketikan bebas, jadi tanpa pemeriksaan ini
        // peserta UTBK tetap bisa menyimpan sekolah kedinasan dengan mengetik
        // namanya. Admin dilewati - ia tidak punya jalur ujian.
        $jalurKampus = function (string $atribut, $nilai, callable $gagal) use ($isAdmin, $kategori): void {
            if (! $isAdmin && TargetKampus::bertentangan(is_string($nilai) ? $nilai : null, $kategori)) {
                $gagal(TargetKampus::pesanGalat($kategori));
            }
        };

        // Peserta boleh mengetik "0812...", "62812...", atau "+62 812-3456-7890";
        // yang divalidasi dan disimpan selalu bentuk bakunya. Menolak mentah-
        // mentah selain "+62" berarti menyalahkan peserta atas format, padahal
        // nomornya sudah benar.
        if ($request->filled('phone_number')) {
            $request->merge([
                'phone_number' => NomorPonsel::keBentukInternasional($request->input('phone_number')),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.AturanMasukan::NAMA_MAKS, 'regex:'.AturanMasukan::NAMA],
            // Dinormalkan ke bentuk +62 sebelum divalidasi, jadi peserta boleh
            // mengetik "0812...", "62812...", atau "+62 812-..." sesukanya dan
            // yang tersimpan tetap satu bentuk.
            'phone_number' => [$profileRequired, 'string', 'max:20', 'regex:'.AturanMasukan::TELEPON],
            'birth_date' => [$profileRequired, 'date'],
            'gender' => [$profileRequired, 'in:L,P'],

            'school_origin' => [$profileRequired, 'string', 'max:255', 'regex:'.AturanMasukan::TEKS_PENDEK],
            'grade_level' => [$profileRequired, 'string', 'max:50', 'regex:'.AturanMasukan::TEKS_PENDEK],
            // Wajib hanya kalau jenjangnya pendidikan tinggi. Siswa SMA aktif
            // tidak punya jurusan, dan memintanya berarti meminta diisi
            // asal-asalan supaya formnya bisa disimpan.
            'education_major' => [
                Jenjang::butuhJurusan($request->input('grade_level')) && ! $isAdmin ? 'required' : 'nullable',
                'string', 'max:255', 'regex:'.AturanMasukan::TEKS_PENDEK,
            ],

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
            'target_university_1' => [$isCpns ? $kedinasanRequired : $targetRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u', $jalurKampus],
            'target_major_1' => [$isCpns ? 'nullable' : $targetRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_university_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u', $jalurKampus],
            'target_major_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],

            'cpns_target_type' => [$isCpns && ! $isAdmin ? 'required' : 'nullable', 'in:kedinasan,umum'],
            'target_instansi_1' => [$umumRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_formasi_1' => [$formasiRequired, 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_instansi_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'target_formasi_2' => ['nullable', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
        ], [
            ...AturanMasukan::pesan(),
            'education_major.required' => 'Jurusan pendidikan terakhir harus diisi.',
            'education_major.regex' => 'Jurusan mengandung karakter yang tidak diperbolehkan.',
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

        // Jurusan hanya berarti untuk jenjang pendidikan tinggi. Siswa SMA aktif
        // yang sebelumnya terdaftar sebagai lulusan akan meninggalkan jurusan
        // lamanya di sana kalau tidak dikosongkan - dan nilai itu tidak akan
        // pernah terlihat lagi untuk diperbaiki.
        if (array_key_exists('grade_level', $sanitized)
            && ! Jenjang::butuhJurusan($sanitized['grade_level'])) {
            $sanitized['education_major'] = null;
        }

        $user->update($sanitized);

        return response()->json([
            'message' => 'Profil berhasil dilengkapi',
            'user' => $user->fresh(),
        ]);
    }
}
