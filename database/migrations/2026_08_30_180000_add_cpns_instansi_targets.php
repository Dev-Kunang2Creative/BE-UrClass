<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Target untuk jalur CPNS, yang selama ini tidak ada.
 *
 * Peserta UTBK memilih target kampus dan jurusan; peserta CPNS melewatinya sama
 * sekali. Padahal pelamar CPNS punya target juga - hanya bentuknya berbeda:
 * instansi dan formasi/jabatan, bukan sekolah dan program studi. Karena itu
 * kolom target kampus yang sudah ada tidak bisa dipakai ulang, dan pasangan
 * kolom ini berdiri sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instansi', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kode', 32)->nullable()->unique();
            $table->string('nama');
            // Pemerintah pusat (kementerian/lembaga) atau daerah (pemprov/pemkab).
            $table->enum('tingkat', ['pusat', 'daerah'])->default('pusat')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('nama');
        });

        Schema::create('formasi', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('instansi_id')->constrained('instansi')->cascadeOnDelete();
            $table->string('nama');
            // Jenjang pendidikan yang disyaratkan formasi, mis. "S-1", "D-III".
            $table->string('jenjang', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['instansi_id', 'nama']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('target_instansi_1')->nullable()->after('target_major_2');
            $table->string('target_formasi_1')->nullable()->after('target_instansi_1');
            $table->string('target_instansi_2')->nullable()->after('target_formasi_1');
            $table->string('target_formasi_2')->nullable()->after('target_instansi_2');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'target_instansi_1',
                'target_formasi_1',
                'target_instansi_2',
                'target_formasi_2',
            ]);
        });

        Schema::dropIfExists('formasi');
        Schema::dropIfExists('instansi');
    }
};
