<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The profile form has asked for Provinsi and Kabupaten/Kota all along, and
 * there was nowhere to put either. ProfileController::update did not validate
 * them, so they never reached $validated and never reached the database; they
 * survived only inside the NextAuth token as front-end overrides, which means
 * they were gone at the next login.
 *
 * region_province and region_city already exist but describe the school
 * location that came with the school picker, so they are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'province')) {
                $table->string('province')->nullable()->after('grade_level');
            }
            if (! Schema::hasColumn('users', 'city')) {
                $table->string('city')->nullable()->after('province');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['province', 'city'],
                fn ($column) => Schema::hasColumn('users', $column)
            ));

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
