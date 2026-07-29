<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->foreignUlid('tryout_id')->constrained('tryouts')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->unsignedInteger('max_usage')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expired_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete(); // Ubah ke foreignUlid
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_codes');
    }
};