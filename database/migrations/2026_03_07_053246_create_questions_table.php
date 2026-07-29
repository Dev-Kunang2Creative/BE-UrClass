<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subtest_id')->constrained('subtests')->cascadeOnDelete(); 
            
            $table->text('question_text')->nullable(); 
            $table->string('question_image')->nullable();
            
            $table->text('discussion')->nullable();
            $table->string('discussion_image')->nullable();
            
            $table->string('correct_answer', 5)->nullable();
            $table->unsignedInteger('order_no')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};