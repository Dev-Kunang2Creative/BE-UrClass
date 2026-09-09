<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sekolah kedinasan kembali masuk jalur CPNS, dan formasi jadi berperiode.
 *
 * Keputusan produk sebelumnya - jalur CPNS hanya melayani pelamar kerja -
 * dibatalkan: sekolah kedinasan tetap bagian dari jalur ini. Jadi kolom yang
 * dulu dihapus dibutuhkan lagi:
 *
 *   - perguruan_tinggi.jenis membedakan PTN dari sekolah kedinasan di tabel yang
 *     sama. Tanpa kolom ini keduanya tidak bisa dipisahkan, dan peserta UTBK
 *     akan menemukan IPDN di daftar target kampusnya.
 *   - users.cpns_target_type menyatakan pasangan kolom target mana yang berlaku
 *     bagi peserta itu, supaya laporan tidak menebak dari kolom mana yang terisi.
 *
 * Ini migrasi maju, bukan pengembalian migrasi 2026_08_30_180000 ke bentuk
 * awalnya. Migrasi itu sudah dijalankan di lingkungan lokal dalam bentuk tanpa
 * kedua kolom ini, sehingga mengeditnya di tempat hanya berlaku bagi yang
 * bermigrasi dari nol - dan memaksa yang lain menjalankan migrate:fresh, yang
 * berarti membuang datanya. Dua migrasi di riwayat lebih murah daripada itu.
 *
 * formasi.periode ditambahkan karena formasi diterbitkan per tahun seleksi.
 * Nilainya informatif - dipakai untuk memberi tahu peserta periode mana yang
 * sedang berlaku - dan boleh kosong untuk baris lama yang periodenya tak
 * diketahui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perguruan_tinggi', function (Blueprint $table) {
            $table->enum('jenis', ['ptn', 'kedinasan'])->default('ptn')->after('nama')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('cpns_target_type', ['kedinasan', 'umum'])
                ->nullable()
                ->after('target_major_2');
        });

        Schema::table('formasi', function (Blueprint $table) {
            $table->unsignedSmallInteger('periode')->nullable()->after('jenjang')->index();
        });
    }

    public function down(): void
    {
        Schema::table('formasi', function (Blueprint $table) {
            $table->dropColumn('periode');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cpns_target_type');
        });

        Schema::table('perguruan_tinggi', function (Blueprint $table) {
            $table->dropColumn('jenis');
        });
    }
};
