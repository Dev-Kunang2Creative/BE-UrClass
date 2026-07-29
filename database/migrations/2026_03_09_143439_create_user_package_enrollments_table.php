<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_package_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('package_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamps();
        
            $table->unique(['user_id','package_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_package_enrollments');
    }
};