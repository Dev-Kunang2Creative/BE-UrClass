<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Target untuk jalur CPNS, yang selama ini tidak ada.
 *
 * Peserta UTBK memilih target kampus dan jurusan; peserta CPNS melewatinya
 * sama sekali. Padahal jalur CPNS punya dua audiens dengan target berbeda
 * bentuk:
 *
 *   - Sekolah kedinasan: sekolah + program studi. Bentuknya sama persis dengan
 *     target PTN, jadi memakai tabel perguruan_tinggi dan program_studi yang
 *     sudah ada, dibedakan kolom jenis. Nilainya pun tersimpan di kolom
 *     target_university_* dan target_major_* yang sudah ada.
 *   - CPNS umum: instansi + formasi/jabatan. Bukan sekolah, jadi butuh tabel
 *     dan kolom sendiri.
 *
 * cpns_target_type menyatakan pasangan kolom mana yang berlaku untuk peserta
 * itu, supaya laporan tidak perlu menebak dari kolom mana yang terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perguruan_tinggi', function (Blueprint $table) {
            $table->enum('jenis', ['ptn', 'kedinasan'])->default('ptn')->after('nama')->index();
        });

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
            $table->enum('cpns_target_type', ['kedinasan', 'umum'])
                ->nullable()
                ->after('target_major_2');
            $table->string('target_instansi_1')->nullable()->after('cpns_target_type');
            $table->string('target_formasi_1')->nullable()->after('target_instansi_1');
            $table->string('target_instansi_2')->nullable()->after('target_formasi_1');
            $table->string('target_formasi_2')->nullable()->after('target_instansi_2');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'cpns_target_type',
                'target_instansi_1',
                'target_formasi_1',
                'target_instansi_2',
                'target_formasi_2',
            ]);
        });

        Schema::dropIfExists('formasi');
        Schema::dropIfExists('instansi');

        Schema::table('perguruan_tinggi', function (Blueprint $table) {
            $table->dropColumn('jenis');
        });
    }
};
