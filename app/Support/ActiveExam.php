<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Apakah seorang peserta sedang mengerjakan tryout.
 *
 * Dipakai untuk menutup asisten AI selama ujian berlangsung. Asisten yang bisa
 * dibuka di tengah tryout mengubah tryout jadi ujian buka-bantuan, dan skor
 * yang dihasilkannya tidak bisa dipakai untuk apa pun - termasuk oleh peserta
 * itu sendiri, yang justru butuh tahu posisinya yang sebenarnya.
 *
 * ## Kenapa bukan sekadar `status = 'in_progress'`
 *
 * Sesi tryout tidak punya kedaluwarsa. Kalau peserta menutup tab di tengah
 * ujian dan tidak pernah kembali, statusnya tetap `in_progress` **selamanya** -
 * hanya sesi subtes yang diberi `expired` , dan itu pun baru saat peserta
 * membuka subtesnya lagi. Memblokir dari status saja berarti satu tryout yang
 * ditinggalkan mengunci asisten untuk akun itu tanpa batas waktu, tanpa ada
 * cara memulihkannya sendiri, dan tanpa pesan yang menjelaskan sebabnya.
 *
 * Jadi pemblokiran diberi batas waktu sendiri: sesi hanya dianggap berjalan
 * selama total durasi subtesnya belum terlampaui. Sesudah itu ujiannya sudah
 * berakhir menurut jam mana pun, apa pun yang tertulis di kolom status.
 *
 * ## Kenapa ada kelonggaran
 *
 * Total durasi subtes bukan lama sebenarnya seseorang duduk di depan tryout:
 * ada jeda antar subtes, ada halaman pengantar, dan ada yang membuka lalu mulai
 * beberapa menit kemudian. Kelonggaran menutupi selisih itu, supaya asisten
 * tidak terbuka lagi di sela dua subtes sementara ujiannya jelas masih berjalan.
 */
class ActiveExam
{
    /**
     * Kelonggaran di atas total durasi subtes, dalam menit.
     *
     * Menutupi jeda antar subtes dan waktu yang terpakai di halaman pengantar.
     * Sengaja tidak besar: setiap menit di sini adalah menit ketika asisten
     * tetap tertutup padahal ujiannya sudah selesai.
     */
    private const KELONGGARAN_MENIT = 45;

    /**
     * Ujian yang sedang dikerjakan peserta, atau null kalau tidak ada.
     *
     * Satu kueri, tanpa loop yang mengueri: durasi subtes dijumlahkan database,
     * dan perbandingan waktunya dilakukan atas beberapa baris yang sudah di
     * memori.
     *
     * @return array{tryout_id: string, title: string, started_at: string, ends_at: string}|null
     */
    public static function for(string $userId): ?array
    {
        $rows = DB::table('tryout_sessions')
            ->join('tryouts', 'tryouts.id', '=', 'tryout_sessions.tryout_id')
            ->leftJoin('tryout_subtests', 'tryout_subtests.tryout_id', '=', 'tryout_sessions.tryout_id')
            ->where('tryout_sessions.user_id', $userId)
            ->where('tryout_sessions.status', 'in_progress')
            ->whereNotNull('tryout_sessions.started_at')
            ->groupBy(
                'tryout_sessions.id',
                'tryout_sessions.tryout_id',
                'tryout_sessions.started_at',
                'tryouts.title',
            )
            ->select('tryout_sessions.tryout_id', 'tryout_sessions.started_at', 'tryouts.title')
            ->selectRaw('COALESCE(SUM(tryout_subtests.duration_minutes), 0) as total_minutes')
            ->get();

        foreach ($rows as $row) {
            $mulai = Carbon::parse($row->started_at);
            $selesai = $mulai->copy()->addMinutes((int) $row->total_minutes + self::KELONGGARAN_MENIT);

            if ($selesai->isFuture()) {
                return [
                    'tryout_id' => (string) $row->tryout_id,
                    'title' => (string) $row->title,
                    'started_at' => $mulai->toIso8601String(),
                    'ends_at' => $selesai->toIso8601String(),
                ];
            }
        }

        return null;
    }

    public static function isTaking(string $userId): bool
    {
        return self::for($userId) !== null;
    }

}
