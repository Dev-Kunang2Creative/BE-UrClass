<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tryouts', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('category');
        });

        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->string('proof_image')->nullable()->after('access_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('tryouts', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });

        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->dropColumn('proof_image');
        });
    }
};