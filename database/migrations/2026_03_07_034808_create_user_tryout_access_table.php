<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tryout_access', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->foreignUlid('tryout_id')->constrained('tryouts')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->foreignUlid('access_code_id')->nullable()->constrained('access_codes')->nullOnDelete(); // Ubah ke foreignUlid
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'tryout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tryout_access');
    }
};