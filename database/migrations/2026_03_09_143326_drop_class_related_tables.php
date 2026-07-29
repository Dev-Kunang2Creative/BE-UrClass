<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_class_enrollments');
        Schema::dropIfExists('package_class');
        Schema::dropIfExists('class_tryout');
        Schema::dropIfExists('study_classes');
    }

    public function down(): void
    {
        Schema::create('study_classes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('class_tryout', function (Blueprint $table) {
            $table->id();
            $table->ulid('study_class_id');
            $table->ulid('tryout_id');
            $table->timestamps();
        });

        Schema::create('package_class', function (Blueprint $table) {
            $table->id();
            $table->ulid('package_id');
            $table->ulid('study_class_id');
            $table->timestamps();
        });

        Schema::create('user_class_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->ulid('study_class_id');
            $table->ulid('order_id')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();
        });
    }
};