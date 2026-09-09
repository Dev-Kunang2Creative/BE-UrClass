<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan pemakaian asisten AI, satu baris per permintaan.
 *
 * Ada karena fitur ini menelan biaya per panggilan dan tidak ada cara lain untuk
 * menjawab pertanyaan yang pasti muncul: berapa yang sudah terpakai, siapa yang
 * memakainya, dan apakah ada yang gagal terus-menerus.
 *
 * Yang **tidak** disimpan: isi pesan peserta dan isi jawaban asisten. Yang
 * dibutuhkan untuk memantau biaya hanyalah jumlah token, dan menyimpan
 * transkrip belajar seseorang adalah beban privasi yang tidak dibutuhkan
 * pemantauan itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Boleh null supaya baris tetap ada setelah akunnya dihapus -
            // biaya yang sudah terjadi tidak hilang hanya karena pemakainya
            // pergi.
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider', 32);
            $table->string('model', 120)->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // Token yang dilayani dari cache provider. Dihitung terpisah karena
            // harganya jauh lebih murah, jadi mencampurnya ke input_tokens
            // membuat estimasi biaya terlalu tinggi.
            $table->unsignedInteger('cached_tokens')->default(0);

            // Biaya dibekukan saat permintaan terjadi, bukan dihitung ulang saat
            // laporan dibuka. Harga bisa diubah admin kapan saja, dan biaya
            // bulan lalu tidak boleh berubah karena harga hari ini berbeda.
            $table->decimal('cost_usd', 12, 6)->default(0);

            $table->enum('status', ['ok', 'failed', 'blocked'])->default('ok');
            // Sebab kegagalan dalam bentuk pendek, mis. "http_401", "quota",
            // "not_configured". Bukan pesan galat mentah - itu bisa memuat
            // kunci dan URL.
            $table->string('reason', 64)->nullable();

            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['created_at', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            // Harga per satu juta token, dalam USD. Diisi admin karena berbeda
            // per provider dan per model, dan berubah tanpa memberi tahu siapa
            // pun.
            $table->decimal('price_input_per_mtok', 10, 4)->default(0)->after('temperature_x100');
            $table->decimal('price_output_per_mtok', 10, 4)->default(0)->after('price_input_per_mtok');
            $table->decimal('price_cached_per_mtok', 10, 4)->default(0)->after('price_output_per_mtok');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn([
                'price_input_per_mtok',
                'price_output_per_mtok',
                'price_cached_per_mtok',
            ]);
        });

        Schema::dropIfExists('ai_usage_logs');
    }
};
