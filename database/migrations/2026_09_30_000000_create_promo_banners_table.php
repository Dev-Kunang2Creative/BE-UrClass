<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banner promosi di halaman pembelian paket, dikelola admin.
 *
 * Sebelumnya enam banner ditulis mati di komponen frontend dan semuanya tampil
 * di kedua jalur sekaligus - banner bertema UTBK ikut muncul di hadapan peserta
 * CPNS, dan menambah banner baru berarti menunggu deploy.
 *
 * `kategori` menentukan di jalur mana sebuah banner muncul: 'utbk', 'cpns',
 * atau 'semua' untuk promo yang tidak terikat jalur. Hanya tiga nilai itu,
 * karena UrClass memang hanya punya dua jalur - sekolah kedinasan adalah
 * sub-jalur di dalam CPNS, bukan jalur ketiga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_banners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('image');
            $table->string('alt');
            $table->string('href')->default('/dashboard/try-out');
            $table->string('kategori')->default('semua');
            $table->integer('order_no')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kategori', 'is_active', 'order_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_banners');
    }
};
