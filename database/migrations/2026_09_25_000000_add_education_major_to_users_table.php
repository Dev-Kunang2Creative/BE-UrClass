<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurusan pendidikan terakhir, untuk peserta CPNS yang sudah lulus kuliah.
 *
 * Jalur CPNS melayani dua audiens: siswa SMA yang membidik sekolah kedinasan,
 * dan wisudawan yang melamar CPNS umum. Yang kedua tidak punya "kelas" - yang
 * relevan justru jenjang terakhir beserta jurusannya.
 *
 * Jenjangnya sendiri tetap ditampung kolom `grade_level` yang sudah ada
 * ("S1", "D3", "Lulusan SMA/SMK", "SMA/SMK Kelas 12"), jadi yang perlu tempat
 * baru hanya jurusannya. Nullable, karena siswa SMA aktif memang tidak
 * mengisinya - dan supaya baris yang sudah ada tidak perlu ditebak isinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('education_major')->nullable()->after('grade_level');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('education_major');
        });
    }
};
