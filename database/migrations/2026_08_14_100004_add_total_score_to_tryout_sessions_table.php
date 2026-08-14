<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tryout_sessions', function (Blueprint $table) {
            $table->decimal('total_score', 10, 2)->default(0)->after('status');
            $table->decimal('raw_score', 10, 2)->default(0)->after('total_score');
            $table->string('scoring_method')->nullable()->after('raw_score');
            $table->boolean('score_finalized')->default(false)->after('scoring_method');

            $table->index(['tryout_id', 'total_score']);
        });
    }

    public function down(): void
    {
        Schema::table('tryout_sessions', function (Blueprint $table) {
            $table->dropIndex(['tryout_id', 'total_score']);
            $table->dropColumn(['total_score', 'raw_score', 'scoring_method', 'score_finalized']);
        });
    }
};
