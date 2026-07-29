<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_class_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('study_class_id')->constrained('study_classes')->cascadeOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'study_class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_class_enrollments');
    }
};