<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Siapa yang sedang menunggu jawaban asisten AI, saat ini.
 *
 * Tabel `ai_usage_logs` hanya tahu permintaan yang **sudah selesai** - barisnya
 * ditulis setelah jawaban tiba. Dari catatan itu, permintaan yang masih
 * berlangsung tidak terlihat sama sekali: seorang peserta yang sudah menunggu
 * dua puluh detik tampak sama dengan yang belum melakukan apa pun. Padahal
 * justru itu yang berarti "sedang memakai".
 *
 * Karena itu keberadaan dicatat terpisah: ditandai saat permintaan dimulai,
 * dihapus saat selesai. Di cache, bukan di tabel - datanya hidup beberapa detik
 * dan tidak ada gunanya sesudah itu, jadi tabel yang perlu dibersihkan berkala
 * adalah beban tanpa imbalan.
 *
 * ## Kenapa satu kunci, bukan satu kunci per pengguna
 *
 * Kunci per pengguna lebih rapi, tapi tidak bisa didaftar: driver cache
 * `database` dan `file` tidak punya cara memindai kunci menurut pola, dan
 * memindai kunci di Redis produksi (`KEYS`) memblokir seluruh server. Satu
 * kunci berisi peta bisa dibaca dengan satu operasi di driver apa pun.
 *
 * Harganya adalah tulisan yang bisa bertabrakan, jadi setiap perubahan diambil
 * di bawah lock.
 *
 * ## Kenapa entri lama dibuang saat dibaca
 *
 * Proses yang mati di tengah permintaan - timeout PHP, worker yang dibunuh,
 * server yang di-restart - tidak pernah sampai ke `selesai()`. Tanpa pembuangan
 * berdasarkan umur, pengguna itu akan tampak "sedang menunggu" selamanya, dan
 * halaman pemantauan berangsur jadi bohong. Umur maksimalnya diikat ke batas
 * waktu permintaan ke provider: lebih lama dari itu, permintaannya pasti sudah
 * berakhir dengan satu atau lain cara.
 */
class AiLivePresence
{
    private const KEY = 'ai-live:presence';

    private const LOCK = 'ai-live:presence:lock';

    /**
     * Umur maksimal satu entri, dalam detik.
     *
     * Sedikit di atas batas waktu permintaan ke provider (60 detik), supaya
     * permintaan yang benar-benar masih berjalan tidak dibuang lebih dulu.
     */
    private const MAKS_UMUR = 90;

    public static function mulai(string $userId): void
    {
        self::ubah(function (array $peta) use ($userId) {
            $peta[$userId] = now()->getTimestamp();

            return $peta;
        });
    }

    public static function selesai(string $userId): void
    {
        self::ubah(function (array $peta) use ($userId) {
            unset($peta[$userId]);

            return $peta;
        });
    }

    /**
     * Pengguna yang sedang menunggu, beserta sudah berapa detik.
     *
     * @return array<string, int> user_id => detik menunggu
     */
    public static function menunggu(): array
    {
        $peta = self::baca();
        $sekarang = now()->getTimestamp();
        $hasil = [];

        foreach ($peta as $userId => $mulai) {
            $umur = $sekarang - (int) $mulai;

            if ($umur >= 0 && $umur <= self::MAKS_UMUR) {
                $hasil[(string) $userId] = $umur;
            }
        }

        return $hasil;
    }

    /** @return array<string, int> */
    private static function baca(): array
    {
        $peta = Cache::get(self::KEY);

        return is_array($peta) ? $peta : [];
    }

    /**
     * @param  callable(array<string, int>): array<string, int>  $ubah
     */
    private static function ubah(callable $ubah): void
    {
        try {
            // Gagal mendapat lock tidak dianggap galat: pencatatan keberadaan
            // adalah pelengkap pemantauan, dan menggagalkan jawaban peserta
            // karena kunci cache sedang dipakai adalah pertukaran yang salah.
            Cache::lock(self::LOCK, 5)->block(2, function () use ($ubah) {
                $peta = $ubah(self::bersihkan(self::baca()));

                if ($peta === []) {
                    Cache::forget(self::KEY);

                    return;
                }

                Cache::put(self::KEY, $peta, self::MAKS_UMUR * 2);
            });
        } catch (Throwable) {
            // Diabaikan dengan sengaja - lihat catatan di atas.
        }
    }

    /**
     * @param  array<string, int>  $peta
     * @return array<string, int>
     */
    private static function bersihkan(array $peta): array
    {
        $batas = now()->getTimestamp() - self::MAKS_UMUR;

        return array_filter($peta, fn ($mulai) => (int) $mulai >= $batas);
    }
}
