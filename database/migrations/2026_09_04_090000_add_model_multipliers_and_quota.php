<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengali token per model, dan jejaknya di tiap baris pemakaian.
 *
 * Sebagian gateway menghitung token sebuah model lebih dari sekali terhadap
 * kuota - kimi-k3 di bandelbanget.xyz dihitung dua kali, misalnya. Tanpa
 * pengali, jumlah token dan biaya yang dicatat aplikasi ini lebih kecil daripada
 * yang benar-benar dipotong dari kuota, dan selisihnya baru terlihat saat kuota
 * habis lebih cepat dari perkiraan.
 *
 * Disimpan sebagai peta model -> pengali yang bisa diatur admin, **bukan
 * ditanam di kode**. Pengali begini adalah kebijakan gateway: ia berbeda antar
 * penyedia dan bisa berubah tanpa memberi tahu siapa pun. Menanam "kimi-k3 = 2"
 * di kode berarti angkanya diam-diam salah begitu gateway atau modelnya
 * berganti.
 *
 * token_multiplier ikut disimpan di tiap baris log supaya angkanya bisa
 * dipertanggungjawabkan: satu baris dengan 4.000 token bisa berarti 2.000 token
 * mentah dengan pengali dua, dan tanpa kolom ini tidak ada cara mengetahuinya
 * setelah pengalinya diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->json('model_multipliers')->nullable()->after('model');
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->decimal('token_multiplier', 6, 2)->default(1)->after('cached_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn('token_multiplier');
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('model_multipliers');
        });
    }
};
