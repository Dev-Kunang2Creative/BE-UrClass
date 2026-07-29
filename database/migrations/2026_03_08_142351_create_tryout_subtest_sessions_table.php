<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tryout_subtest_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tryout_session_id')
                ->constrained('tryout_sessions')
                ->cascadeOnDelete();

            $table->foreignUlid('tryout_subtest_id')
                ->constrained('tryout_subtests')
                ->cascadeOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->string('status')->default('not_started');
            $table->timestamps();

            $table->unique(
                ['tryout_session_id', 'tryout_subtest_id'],
                'tss_session_subtest_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tryout_subtest_sessions');
    }
};