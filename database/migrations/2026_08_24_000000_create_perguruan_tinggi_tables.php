<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perguruan_tinggi', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Official SNPMB code. Keyed on this rather than the name so a
            // reseed with fresher data updates rows instead of duplicating
            // them, and so renames do not orphan a student's saved target.
            $table->string('kode_ptn', 16)->unique();
            $table->string('nama');
            $table->timestamps();

            $table->index('nama');
        });

        Schema::create('program_studi', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('perguruan_tinggi_id')
                ->constrained('perguruan_tinggi')
                ->cascadeOnDelete();
            $table->string('kode_prodi', 24)->unique();
            $table->string('nama');
            $table->string('jenjang', 32);

            // Straight from the SNPMB tables. Nullable because not every prodi
            // publishes both figures, and a missing quota must stay
            // distinguishable from a quota of zero.
            $table->unsignedInteger('daya_tampung')->nullable();
            $table->unsignedInteger('peminat')->nullable();
            $table->string('jenis_portofolio')->nullable();

            $table->timestamps();

            $table->index('nama');
            $table->index('jenjang');
            // Listing a university's programmes is the common query.
            $table->index(['perguruan_tinggi_id', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_studi');
        Schema::dropIfExists('perguruan_tinggi');
    }
};
