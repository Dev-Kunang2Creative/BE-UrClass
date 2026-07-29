<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tryout_sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['tryout_id']);
            $table->dropUnique(['user_id', 'tryout_id']);
            $table->unsignedInteger('attempt_number')->default(1)->after('tryout_id');
            $table->unique(
                ['user_id', 'tryout_id', 'attempt_number'],
                'tryout_sessions_user_tryout_attempt_unique'
            );
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tryout_id')->references('id')->on('tryouts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tryout_sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['tryout_id']);
            $table->dropUnique('tryout_sessions_user_tryout_attempt_unique');
            $table->dropColumn('attempt_number');
            $table->unique(['user_id', 'tryout_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tryout_id')->references('id')->on('tryouts')->cascadeOnDelete();
        });
    }
};
