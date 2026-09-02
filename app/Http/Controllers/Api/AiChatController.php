<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\AiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Satu-satunya endpoint chat yang dikenal frontend.
 *
 * Yang diketahui browser cuma ini. Endpoint provider, kunci API, dan persona
 * seluruhnya tinggal di server - jadi tidak ada yang bisa dibaca dari tab
 * jaringan, tidak ada yang perlu dipercayakan ke klien, dan mengganti provider
 * tidak menyentuh frontend sama sekali.
 */
class AiChatController extends Controller
{
    /** Keadaan asisten, dibaca frontend untuk memutuskan menampilkan tombolnya. */
    public function status(Request $request): JsonResponse
    {
        $setting = AiSetting::current();
        $aktif = $setting->isUsable();

        return response()->json([
            'data' => [
                'is_available' => $aktif,
                'daily_limit' => $setting->daily_message_limit,
                'used_today' => $aktif ? $this->usedToday($request->user()->id) : 0,
                'max_message_length' => AiChatService::MAX_MESSAGE_LENGTH,
            ],
        ]);
    }

    public function send(Request $request, AiChatService $chat): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:'.AiChatService::MAX_MESSAGE_LENGTH],
            // Riwayat dikirim klien karena percakapan tidak disimpan di server.
            // Isinya tetap disaring dan dipotong di server - lihat AiChatService.
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:'.AiChatService::MAX_MESSAGE_LENGTH],
        ], [
            'message.required' => 'Tulis pertanyaanmu dulu.',
            'message.max' => 'Pesan terlalu panjang. Maksimal '.AiChatService::MAX_MESSAGE_LENGTH.' karakter.',
        ]);

        $setting = AiSetting::current();

        if (! $setting->isUsable()) {
            return response()->json([
                'message' => 'Asisten AI belum tersedia. Coba lagi nanti ya.',
            ], 503);
        }

        $user = $request->user();
        $terpakai = $this->usedToday($user->id);

        // Setiap panggilan berbiaya uang. Tanpa batas per akun, satu pengguna -
        // atau satu skrip yang memakai token seseorang - bisa menghabiskan
        // anggaran sendirian dalam satu malam.
        if ($terpakai >= $setting->daily_message_limit) {
            return response()->json([
                'message' => "Kuota chat hari ini sudah habis ({$setting->daily_message_limit} pesan). Balik lagi besok ya.",
                'data' => ['used_today' => $terpakai, 'daily_limit' => $setting->daily_message_limit],
            ], 429);
        }

        try {
            $hasil = $chat->send($setting, $validated['message'], $validated['history'] ?? []);
        } catch (RuntimeException $e) {
            // Pesannya sudah diterjemahkan untuk peserta di AiChatService.
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            // Galat tak terduga tidak dikirim apa adanya: isinya bisa memuat URL
            // endpoint atau potongan header.
            Log::error('Chat AI gagal', ['exception' => $e->getMessage()]);

            return response()->json(['message' => 'Asisten AI gagal menjawab. Coba lagi sebentar.'], 502);
        }

        $terpakai = $this->increment($user->id);

        return response()->json([
            'data' => [
                'reply' => $hasil['reply'],
                'used_today' => $terpakai,
                'daily_limit' => $setting->daily_message_limit,
            ],
        ]);
    }

    /**
     * Hitungan disimpan di cache dengan kunci per pengguna per hari, jadi ia
     * kedaluwarsa sendiri dan tidak perlu tabel maupun pembersihan terjadwal.
     */
    private function cacheKey(string $userId): string
    {
        return 'ai-chat:'.$userId.':'.now()->toDateString();
    }

    private function usedToday(string $userId): int
    {
        return (int) Cache::get($this->cacheKey($userId), 0);
    }

    private function increment(string $userId): int
    {
        $key = $this->cacheKey($userId);

        // Dihitung setelah jawaban berhasil, bukan sebelum: permintaan yang
        // gagal karena provider bermasalah tidak seharusnya memakan kuota
        // peserta.
        $baru = $this->usedToday($userId) + 1;

        Cache::put($key, $baru, now()->endOfDay());

        return $baru;
    }
}
