<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtest_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('exam_type', 20); // 'utbk' | 'cpns'
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['exam_type', 'is_active']);
        });

        Schema::table('subtests', function (Blueprint $table) {
            $table->string('category', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtest_categories');
    }
};
