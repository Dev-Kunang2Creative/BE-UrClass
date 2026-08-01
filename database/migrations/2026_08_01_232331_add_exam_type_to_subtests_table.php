<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtests', function (Blueprint $table) {
            $table->enum('exam_type', ['utbk', 'cpns'])->default('utbk')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('subtests', function (Blueprint $table) {
            $table->dropColumn('exam_type');
        });
    }
};
