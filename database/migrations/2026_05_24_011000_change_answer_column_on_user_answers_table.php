<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_answers', function (Blueprint $table) {
            $table->longText('answer')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_answers', function (Blueprint $table) {
            $table->string('answer', 5)->nullable()->change();
        });
    }
};
