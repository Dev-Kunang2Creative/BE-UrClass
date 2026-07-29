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
        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->boolean('discussion_unlocked')->default(false)->after('granted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->dropColumn('discussion_unlocked');
        });
    }
};
