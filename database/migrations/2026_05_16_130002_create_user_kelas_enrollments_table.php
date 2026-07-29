<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_kelas_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignUlid('kelas_order_id')->nullable()->constrained('kelas_orders')->nullOnDelete();
            $table->timestamp('enrolled_at');
            $table->timestamps();
            $table->unique(['user_id', 'kelas_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_kelas_enrollments');
    }
};
