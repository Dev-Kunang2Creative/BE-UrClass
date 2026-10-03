<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mematikan IRT pada tryout CPNS yang dibuat sebelum aturannya ditegakkan.
 *
 * Sejak 30 September TryoutController memaksa use_irt mengikuti jalur: UTBK
 * selalu IRT, CPNS tidak pernah. Tapi itu hanya berlaku saat tryout dibuat atau
 * disunting. Sebelumnya saklar IRT di form menyala secara bawaan - kolomnya pun
 * bawaan true - jadi tryout CPNS lama yang tidak pernah disentuh lagi masih
 * tersimpan dengan IRT menyala, dan skornya dihitung di skala 0-1000 alih-alih
 * 550 dengan ambang kelulusan SKD yang mutlak.
 *
 * Hanya CPNS. Tryout UTBK yang kebetulan IRT-nya mati tidak diubah di sini:
 * menyalakan IRT menggeser skor peserta yang sudah ada, dan itu keputusan yang
 * berbeda dari memperbaiki SKD yang memang tidak pernah boleh memakai IRT.
 *
 * Tidak ada down(): mengembalikannya berarti memasang lagi keadaan yang salah,
 * dan tryout mana yang dulu menyala tidak lagi bisa dibedakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tryouts')
            ->where('kategori', 'cpns')
            ->where('use_irt', true)
            ->update(['use_irt' => false]);
    }

    public function down(): void
    {
        //
    }
};
