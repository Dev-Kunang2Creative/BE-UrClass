<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('school_id')->nullable()->after('kategori');
            $table->string('school_name')->nullable()->after('school_id');
            $table->string('region_province')->nullable()->after('school_name');
            $table->string('region_city')->nullable()->after('region_province');

            $table->index('school_id');
            $table->index('region_province');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['school_id']);
            $table->dropIndex(['region_province']);
            $table->dropColumn(['school_id', 'school_name', 'region_province', 'region_city']);
        });
    }
};
