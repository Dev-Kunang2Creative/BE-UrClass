<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->after('google_id');
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['L', 'P'])->nullable();
            
            // Data Akademik
            $table->string('school_origin')->nullable();
            $table->string('grade_level')->nullable();
            
            // Target Kuliah
            $table->string('target_university_1')->nullable();
            $table->string('target_major_1')->nullable();
            $table->string('target_university_2')->nullable();
            $table->string('target_major_2')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone_number',
                'birth_date',
                'gender',
                'school_origin',
                'grade_level',
                'target_university_1',
                'target_major_1',
                'target_university_2',
                'target_major_2',
            ]);
        });
    }
};