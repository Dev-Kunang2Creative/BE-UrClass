<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengenkripsi nilai ai_settings.endpoint yang masih tersimpan sebagai teks
 * biasa.
 *
 * Cast `encrypted` ditambahkan ke kolom endpoint setelah kolom itu sudah terisi.
 * Menambahkan cast tidak menyentuh baris yang sudah ada, jadi Eloquent kemudian
 * mencoba mendekripsi teks biasa dan melempar DecryptException - yang muncul ke
 * pengguna sebagai **"The payload is invalid."** pada setiap permintaan chat,
 * tanpa petunjuk sama sekali bahwa sebabnya ada di kolom ini.
 *
 * Ditemukan di lingkungan lokal setelah cast-nya masuk. Lingkungan dev dan
 * produksi akan rusak dengan cara yang sama pada permintaan chat pertama setelah
 * deploy, jadi konversinya harus ikut dikirim - bukan diperbaiki tangan per
 * lingkungan.
 *
 * Idempoten: baris yang sudah terenkripsi dilewati, jadi menjalankannya ulang
 * tidak mengenkripsi dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        foreach (DB::table('ai_settings')->get(['id', 'endpoint']) as $row) {
            $nilai = $row->endpoint;

            if ($nilai === null || $nilai === '' || self::sudahTerenkripsi($nilai)) {
                continue;
            }

            DB::table('ai_settings')
                ->where('id', $row->id)
                ->update(['endpoint' => Crypt::encryptString($nilai)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        foreach (DB::table('ai_settings')->get(['id', 'endpoint']) as $row) {
            $nilai = $row->endpoint;

            if ($nilai === null || $nilai === '' || ! self::sudahTerenkripsi($nilai)) {
                continue;
            }

            try {
                DB::table('ai_settings')
                    ->where('id', $row->id)
                    ->update(['endpoint' => Crypt::decryptString($nilai)]);
            } catch (\Throwable) {
                // Tidak bisa didekripsi berarti APP_KEY sudah berganti. Dibiarkan
                // apa adanya: menuliskan nilai rusak ke kolom lebih buruk
                // daripada meninggalkannya untuk diisi ulang admin.
            }
        }
    }

    /**
     * Nilai terenkripsi Laravel adalah JSON ber-base64 yang memuat iv, value,
     * dan mac. Memeriksa strukturnya lebih dapat dipercaya daripada menebak dari
     * awalan teksnya, karena awalan base64 bisa berubah.
     */
    private static function sudahTerenkripsi(string $nilai): bool
    {
        $decoded = base64_decode($nilai, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
};
