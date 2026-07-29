<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_answers', function (Blueprint $table) {
            $table->id();
            
            $table->foreignUlid('tryout_session_id')->constrained('tryout_sessions')->cascadeOnDelete();
            
            $table->foreignUlid('question_id')->constrained('questions')->cascadeOnDelete();
            
            $table->string('answer', 5)->nullable(); 
            $table->boolean('is_correct')->default(false);
            $table->integer('score')->default(0); 
            
            $table->timestamps();
            
            $table->unique(['tryout_session_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_answers');
    }
};