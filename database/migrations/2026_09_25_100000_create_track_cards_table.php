<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teks kartu pemilihan jalur, supaya admin bisa mengubahnya sendiri.
 *
 * Tabel ini menyimpan **perubahan**, bukan seluruh isinya. Teks bawaannya
 * tinggal di TrackCard::BAWAAN, dan baris di sini ditimpakan di atasnya. Dua
 * akibat yang disengaja:
 *
 * 1. Tidak ada seeder yang perlu dijalankan. Begitu migrasi ini selesai,
 *    kartunya sudah tampil benar meski tabelnya masih kosong - penting karena
 *    deploy produksi tidak pernah menjalankan seeder.
 * 2. Tidak ada peluang isian ganda antara migrasi dan seeder, kejadian yang
 *    sudah pernah menimpa proof_requirements.
 *
 * Kuncinya kategori jalur itu sendiri: hanya ada dua dan keduanya tetap, jadi
 * tidak ada yang perlu dibuat atau dihapus admin - hanya diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_cards', function (Blueprint $table) {
            $table->string('kategori')->primary(); // 'utbk' | 'cpns'
            $table->string('title')->nullable();
            $table->string('badge')->nullable();
            $table->string('cta')->nullable();
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_cards');
    }
};
