<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tryout_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->foreignUlid('tryout_id')->constrained('tryouts')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status')->default('not_started');
            $table->timestamps();

            $table->unique(['user_id', 'tryout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tryout_sessions');
    }
};