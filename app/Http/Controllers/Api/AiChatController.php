<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
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
            $this->log($setting, $request->user()->id, 'blocked', 'not_configured');

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
            $this->log($setting, $user->id, 'blocked', 'quota');

            return response()->json([
                'message' => "Kuota chat hari ini sudah habis ({$setting->daily_message_limit} pesan). Balik lagi besok ya.",
                'data' => ['used_today' => $terpakai, 'daily_limit' => $setting->daily_message_limit],
            ], 429);
        }

        $mulai = microtime(true);

        try {
            $hasil = $chat->send($setting, $validated['message'], $validated['history'] ?? []);
        } catch (RuntimeException $e) {
            // Pesannya sudah diterjemahkan untuk peserta di AiChatService.
            // Yang dicatat kode sebabnya, bukan pesannya - pesan galat provider
            // bisa memuat kunci dan URL.
            $this->log($setting, $user->id, 'failed', $e->getCode() ? 'http_'.$e->getCode() : 'provider', [], $mulai);

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            // Galat tak terduga tidak dikirim apa adanya: isinya bisa memuat URL
            // endpoint atau potongan header.
            Log::error('Chat AI gagal', ['exception' => $e->getMessage()]);
            $this->log($setting, $user->id, 'failed', 'exception', [], $mulai);

            return response()->json(['message' => 'Asisten AI gagal menjawab. Coba lagi sebentar.'], 502);
        }

        $this->log($setting, $user->id, 'ok', null, $hasil['usage'], $mulai, $hasil['model']);

        $terpakai = $this->increment($user->id);

        return response()->json([
            'data' => [
                'reply' => $hasil['reply'],
                'used_today' => $terpakai,
                'daily_limit' => $setting->daily_message_limit,
                // Jumlah token yang benar-benar terpakai, supaya antarmuka
                // menampilkan angka dari provider - bukan perkiraannya sendiri.
                'usage' => [
                    'input_tokens' => (int) ($hasil['usage']['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($hasil['usage']['output_tokens'] ?? 0),
                    'cached_tokens' => (int) ($hasil['usage']['cached_tokens'] ?? 0),
                ],
            ],
        ]);
    }

    /**
     * Mencatat satu permintaan.
     *
     * Dicatat juga yang gagal dan yang ditolak kuota - justru itu yang perlu
     * terlihat di pemantauan. Kegagalan yang berulang tanpa jejak adalah cara
     * paling mudah kehabisan anggaran atau membiarkan asisten mati berhari-hari
     * tanpa ada yang tahu.
     *
     * Sengaja tidak melempar galat: kegagalan mencatat tidak boleh
     * menggagalkan jawaban yang sudah berhasil didapat peserta.
     *
     * @param  array<string, int>  $usage
     */
    private function log(
        AiSetting $setting,
        ?string $userId,
        string $status,
        ?string $reason = null,
        array $usage = [],
        ?float $mulai = null,
        ?string $model = null,
    ): void {
        try {
            $input = (int) ($usage['input_tokens'] ?? 0);
            $output = (int) ($usage['output_tokens'] ?? 0);
            $cached = (int) ($usage['cached_tokens'] ?? 0);

            AiUsageLog::create([
                'user_id' => $userId,
                'provider' => $setting->provider,
                'model' => $model ?? $setting->model,
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cached_tokens' => $cached,
                'cost_usd' => AiUsageLog::estimateCost($setting, $input, $output, $cached),
                'status' => $status,
                'reason' => $reason,
                'duration_ms' => $mulai ? (int) round((microtime(true) - $mulai) * 1000) : 0,
            ]);
        } catch (Throwable $e) {
            Log::warning('Gagal mencatat pemakaian AI', ['exception' => $e->getMessage()]);
        }
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
