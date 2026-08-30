<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kode redeem yang kedaluwarsanya diisi tanggal saja mati sejak tengah malam.
 *
 * Form admin mengirim tanggal polos, dan nilainya tersimpan sebagai pukul
 * 00:00 hari itu. Akibatnya kode yang disetel "berlaku sampai 30 Agustus"
 * sudah kedaluwarsa sepanjang tanggal 30 Agustus - seluruh hari yang justru
 * dimaksudkan masih berlaku.
 *
 * Baris yang jamnya tepat 00:00:00 digeser ke akhir hari yang sama. Nilai yang
 * memang punya jam tidak disentuh, karena di sana jamnya disengaja.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ticket_redeem_codes')
            ->whereNotNull('expired_at')
            ->whereRaw('TIME(expired_at) = ?', ['00:00:00'])
            ->update([
                'expired_at' => DB::raw("DATE_ADD(DATE(expired_at), INTERVAL '23:59:59' HOUR_SECOND)"),
            ]);
    }

    public function down(): void
    {
        DB::table('ticket_redeem_codes')
            ->whereNotNull('expired_at')
            ->whereRaw('TIME(expired_at) = ?', ['23:59:59'])
            ->update(['expired_at' => DB::raw('DATE(expired_at)')]);
    }
};
