<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durasi ujian CPNS pindah ke level tryout.
 *
 * Sebelumnya batas waktu SKD adalah penjumlahan duration_minutes tiap subtes,
 * peninggalan dari alur UTBK yang memang dikerjakan subtes per subtes. Untuk
 * CPNS seluruh soal dikerjakan dalam satu waktu dan peserta bebas berpindah
 * bagian, jadi angkanya cuma satu - dan menyimpannya sebagai tiga angka yang
 * dijumlahkan membuat admin harus membagi 100 menit ke TWK/TIU/TKP padahal
 * pembagian itu tidak pernah dipakai saat ujian berlangsung.
 *
 * Nullable, bukan default 100: tryout CPNS yang sudah ada tetap memakai
 * penjumlahan lamanya, sehingga batas waktu tryout yang sedang berjalan tidak
 * berubah diam-diam saat migrasi ini jalan. Tryout baru diisi 100 oleh
 * TryoutController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tryouts', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('kategori');
        });
    }

    public function down(): void
    {
        Schema::table('tryouts', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
