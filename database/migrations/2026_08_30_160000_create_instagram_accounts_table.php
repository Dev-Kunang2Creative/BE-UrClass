<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akun Instagram yang wajib di-follow untuk mengikuti tryout gratis.
 *
 * Sebelumnya dua akun ditulis langsung di halaman detail tryout, dan jumlah
 * bukti yang diminta backend dipatok 2 - kebetulan cocok karena akunnya memang
 * dua. Mengganti atau menambah akun berarti mengubah kode di dua tempat dan
 * berharap keduanya tidak lupa disamakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('username')->unique();
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('order_no')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'order_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
