<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_feedbacks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('tryout_id')->constrained('tryouts')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->index();
            $table->string('category', 40);
            $table->text('comment');
            $table->timestamps();
            $table->unique(['user_id', 'tryout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_feedbacks');
    }
};
