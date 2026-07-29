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
        Schema::create('package_class', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignUlid('study_class_id')->constrained('study_classes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['package_id', 'study_class_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_class');
    }
};