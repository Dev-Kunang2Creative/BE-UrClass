<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akun Instagram peserta.
 *
 * Diminta supaya admin bisa mengenali siapa peserta di balik sebuah nama -
 * misalnya peringkat satu leaderboard - dan menghubunginya. Nama dan email saja
 * tidak cukup untuk itu.
 *
 * Yang disimpan hanya username-nya, tanpa "@" dan bukan URL, dalam huruf kecil
 * (lihat App\Support\AkunInstagram). 30 karakter adalah batas Instagram sendiri.
 * Nullable karena opsional: tidak semua peserta punya Instagram, dan akun yang
 * sudah ada tidak perlu ditebak isinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('instagram', 30)->nullable()->after('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('instagram');
        });
    }
};
