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
        Schema::create('package_tryout', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignUlid('tryout_id')->constrained('tryouts')->cascadeOnDelete();
            $table->timestamps();
        
            $table->unique(['package_id','tryout_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_tryout');
    }
};