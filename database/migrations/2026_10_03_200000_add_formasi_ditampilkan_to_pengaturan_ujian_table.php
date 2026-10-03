<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar admin untuk menyembunyikan formasi dari form profil peserta.
 *
 * Status "formasi dibuka" sebelumnya hanya diturunkan dari data: begitu ada satu
 * formasi aktif, kolomnya muncul. Admin perlu bisa menahannya - misalnya selama
 * rekap formasi masih diisi atau diperiksa - tanpa harus menghapus datanya.
 *
 * Bawaannya true, supaya perilaku yang sudah berjalan tidak berubah sampai admin
 * sendiri yang mematikannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengaturan_ujian', function (Blueprint $table) {
            $table->boolean('formasi_ditampilkan')->default(true)->after('skd_passing_grade_tkp');
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_ujian', function (Blueprint $table) {
            $table->dropColumn('formasi_ditampilkan');
        });
    }
};
