<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_ujian', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedSmallInteger('skd_passing_grade_twk')->default(65);
            $table->unsignedSmallInteger('skd_passing_grade_tiu')->default(80);
            $table->unsignedSmallInteger('skd_passing_grade_tkp')->default(166);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_ujian');
    }
};
