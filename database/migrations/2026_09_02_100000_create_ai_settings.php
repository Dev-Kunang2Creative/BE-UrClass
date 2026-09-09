<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfigurasi asisten AI, satu baris untuk seluruh aplikasi.
 *
 * Kredensialnya tidak ditaruh di .env karena pengguna ingin bisa menggantinya
 * dari panel admin tanpa deploy. Konsekuensinya kredensial itu ada di database,
 * jadi dua hal wajib berlaku dan keduanya diberlakukan di lapisan model:
 *
 *   1. api_key terenkripsi saat disimpan (cast `encrypted`, kuncinya APP_KEY),
 *      sehingga dump database tidak langsung memuat kunci yang bisa dipakai.
 *   2. api_key tidak pernah dikirim ke klien mana pun - bahkan ke admin.
 *      Endpoint admin mengirim bentuk tersamar ("sk-or...4f2a"), cukup untuk
 *      memastikan kunci mana yang terpasang tanpa bisa dipakai ulang.
 *
 * Permintaan ke provider dilakukan server, jadi endpoint dan kunci tidak pernah
 * menyentuh browser. Frontend hanya tahu satu hal: /api/chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Bentuk request berbeda per provider, jadi ini yang menentukan
            // adapter mana yang dipakai - bukan ditebak dari URL endpoint.
            $table->enum('provider', ['openai_compatible', 'anthropic'])
                ->default('openai_compatible');

            $table->string('endpoint')->nullable();

            // Panjang: hasil enkripsi Laravel jauh lebih panjang dari kunci
            // aslinya, jadi text - bukan string(255).
            $table->text('api_key')->nullable();

            $table->string('model')->nullable();

            // Persona disimpan di server, bukan di frontend. Kalau ada di
            // frontend, siapa pun bisa membacanya dan menyusun permintaan yang
            // mengabaikannya.
            $table->text('system_prompt')->nullable();

            $table->unsignedSmallInteger('max_tokens')->default(2048);
            // Disimpan sebagai integer per seratus (70 = 0.7) supaya tidak ada
            // pembulatan float yang mengejutkan saat disimpan dan dibaca ulang.
            $table->unsignedSmallInteger('temperature_x100')->default(70);

            // Batas pemakaian per peserta. Setiap panggilan berbiaya uang, jadi
            // tanpa batas satu akun bisa menghabiskan anggaran sendirian.
            $table->unsignedSmallInteger('daily_message_limit')->default(30);
            // Riwayat yang dikirim ulang ke provider. Makin panjang makin mahal
            // dan makin lambat, jadi dibatasi dan bisa diatur.
            $table->unsignedTinyInteger('history_limit')->default(10);

            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
