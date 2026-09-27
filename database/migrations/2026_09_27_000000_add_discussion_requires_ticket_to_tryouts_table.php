<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah pembahasan tryout gratis menagih satu tiket.
 *
 * Sebelumnya aturannya mati di kode: tryout gratis selalu mengunci pembahasan
 * di balik satu tiket, tryout berbayar selalu membukanya. Tidak semua tryout
 * gratis diniatkan begitu - sebagian memang dibagikan cuma-cuma sampai ke
 * pembahasannya, misalnya tryout perkenalan.
 *
 * Bawaannya true supaya seluruh tryout gratis yang sudah ada tetap berperilaku
 * persis seperti sekarang. Kolom ini tidak berlaku untuk tryout berbayar, yang
 * pembahasannya memang sudah termasuk dalam tiket yang dipakai mengerjakan -
 * nilainya tetap disimpan apa adanya supaya tidak hilang kalau admin sempat
 * mengubah tryout itu jadi gratis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tryouts', function (Blueprint $table) {
            $table->boolean('discussion_requires_ticket')->default(true)->after('is_free');
        });
    }

    public function down(): void
    {
        Schema::table('tryouts', function (Blueprint $table) {
            $table->dropColumn('discussion_requires_ticket');
        });
    }
};
