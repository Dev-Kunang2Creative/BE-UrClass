<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtests', function (Blueprint $table) {
            $table->enum('scoring_scheme', ['irt', 'right_wrong', 'option_weight'])
                ->default('right_wrong')
                ->after('exam_type');
            $table->decimal('score_correct', 8, 2)->default(1)->after('scoring_scheme');
            $table->decimal('score_wrong', 8, 2)->default(0)->after('score_correct');
            $table->decimal('score_empty', 8, 2)->default(0)->after('score_wrong');
        });
    }

    public function down(): void
    {
        Schema::table('subtests', function (Blueprint $table) {
            $table->dropColumn(['scoring_scheme', 'score_correct', 'score_wrong', 'score_empty']);
        });
    }
};
