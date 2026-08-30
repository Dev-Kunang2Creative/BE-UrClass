<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kategori tryout hanya UTBK dan CPNS.
 *
 * Kolom category dulu menampung sub-kategori bebas (UM, SNBP, SKD, SKB,
 * Kedinasan), padahal dalam praktiknya isinya selalu satu nilai per jalur -
 * filter jenis di halaman tryout siswa sudah dihapus karena setiap pilihan
 * selain yang pertama justru menyaring habis semua data. Nilainya sekarang
 * diturunkan dari kolom kategori, sehingga tidak mungkin ada tryout berjalur
 * CPNS tapi berkategori SNBP.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tryouts')->where('kategori', 'cpns')->update(['category' => 'CPNS']);
        DB::table('tryouts')->where('kategori', '!=', 'cpns')->update(['category' => 'UTBK']);
        DB::table('tryouts')->whereNull('kategori')->update(['category' => 'UTBK']);
    }

    public function down(): void
    {
        // Tidak ada yang bisa dipulihkan: sub-kategori lama tidak disimpan di
        // tempat lain, dan menebaknya kembali dari judul akan mengarang data.
    }
};
