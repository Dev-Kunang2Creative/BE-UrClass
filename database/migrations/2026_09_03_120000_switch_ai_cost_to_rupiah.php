<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya asisten AI dicatat dan ditampilkan dalam Rupiah.
 *
 * Sebelumnya dalam USD, karena harga provider terbit dalam USD. Tapi yang
 * membaca laporan ini mengelola anggaran dalam Rupiah, dan angka dolar berdesimal
 * enam ("$0.000284") tidak bisa dinilai besar-kecilnya oleh siapa pun tanpa
 * menghitung dulu.
 *
 * **Tidak ada kurs yang disimpan.** Admin memasukkan harga langsung dalam Rupiah
 * per satu juta token. Alternatifnya - menyimpan harga USD plus satu kolom kurs -
 * menambah angka yang diam-diam basi: kurs berubah tiap hari, tidak ada yang
 * ingat memperbaruinya, dan seluruh laporan jadi salah tanpa tanda apa pun.
 * Dengan satu angka, yang tersimpan persis yang dipakai.
 *
 * ## Soal konversi data yang sudah ada
 *
 * Baris yang sudah tercatat dikonversi dengan kurs di bawah. Angka itu **asumsi
 * untuk mempertahankan riwayat secara proporsional**, bukan kurs yang
 * diverifikasi pada tanggal transaksinya - riwayat menyimpan biaya, bukan kurs
 * saat itu, jadi konversi yang benar-benar akurat per baris memang tidak mungkin.
 *
 * Harga per token juga dikonversi dengan kurs yang sama, dan **admin sebaiknya
 * memeriksanya** terhadap halaman harga providernya setelah pembaruan ini -
 * angka hasil konversi ini hanya titik awal yang masuk akal.
 */
return new class extends Migration
{
    /**
     * Kurs yang dipakai untuk mengonversi data lama. Asumsi, bukan kurs
     * terverifikasi - lihat catatan di atas.
     */
    private const KURS_ASUMSI = 16_000;

    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            // Nama kolomnya menyebut USD secara eksplisit, jadi ia harus ikut
            // berubah - kolom bernama cost_usd yang berisi Rupiah adalah jebakan
            // untuk siapa pun yang membacanya nanti.
            $table->renameColumn('cost_usd', 'cost_idr');
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            // Rupiah butuh rentang yang jauh lebih besar daripada USD: satu juta
            // token bisa berbiaya puluhan ribu, dan total bulanan bisa jutaan.
            $table->decimal('cost_idr', 16, 4)->default(0)->change();
        });

        DB::table('ai_usage_logs')
            ->where('cost_idr', '>', 0)
            ->update(['cost_idr' => DB::raw('cost_idr * '.self::KURS_ASUMSI)]);

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->decimal('price_input_per_mtok', 14, 2)->default(0)->change();
            $table->decimal('price_output_per_mtok', 14, 2)->default(0)->change();
            $table->decimal('price_cached_per_mtok', 14, 4)->default(0)->change();
        });

        foreach (['price_input_per_mtok', 'price_output_per_mtok', 'price_cached_per_mtok'] as $kolom) {
            DB::table('ai_settings')
                ->where($kolom, '>', 0)
                ->update([$kolom => DB::raw($kolom.' * '.self::KURS_ASUMSI)]);
        }
    }

    public function down(): void
    {
        foreach (['price_input_per_mtok', 'price_output_per_mtok', 'price_cached_per_mtok'] as $kolom) {
            DB::table('ai_settings')
                ->where($kolom, '>', 0)
                ->update([$kolom => DB::raw($kolom.' / '.self::KURS_ASUMSI)]);
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->decimal('price_input_per_mtok', 10, 4)->default(0)->change();
            $table->decimal('price_output_per_mtok', 10, 4)->default(0)->change();
            $table->decimal('price_cached_per_mtok', 10, 4)->default(0)->change();
        });

        DB::table('ai_usage_logs')
            ->where('cost_idr', '>', 0)
            ->update(['cost_idr' => DB::raw('cost_idr / '.self::KURS_ASUMSI)]);

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->decimal('cost_idr', 12, 6)->default(0)->change();
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->renameColumn('cost_idr', 'cost_usd');
        });
    }
};
