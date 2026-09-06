<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tryout_sessions', fn (Blueprint $table) => $table->timestamp('batas_waktu')->nullable());
    }

    public function down(): void
    {
        Schema::table('tryout_sessions', fn (Blueprint $table) => $table->dropColumn('batas_waktu'));
    }
};
