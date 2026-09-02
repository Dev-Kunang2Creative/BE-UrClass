<?php

namespace App\Services;

use App\Models\AiSetting;
use App\Support\SafeOutboundUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Perantara ke provider AI.
 *
 * Seluruh permintaan ke provider terjadi di sini, di server. Frontend tidak
 * pernah tahu endpoint maupun kuncinya - ia hanya memanggil /api/chat. Itu
 * bukan sekadar rapi: kunci API yang pernah menyentuh browser harus dianggap
 * bocor, karena siapa pun bisa membacanya dari tab jaringan.
 *
 * Dua provider didukung karena bentuk requestnya berbeda, dan yang menentukan
 * adalah kolom `provider` - bukan tebakan dari URL endpoint. Endpoint yang sama
 * bisa melayani bentuk berbeda (banyak gateway menyediakan keduanya), jadi
 * menebak dari URL akan salah tepat pada kasus yang paling sering dipakai.
 */
class AiChatService
{
    /** Batas waktu satu permintaan ke provider. */
    private const TIMEOUT_SECONDS = 60;

    /** Batas panjang satu pesan pengguna, dihitung karakter. */
    public const MAX_MESSAGE_LENGTH = 4000;

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, model: string, usage: array<string, int>}
     */
    public function send(AiSetting $setting, string $message, array $history = []): array
    {
        if (! $setting->isUsable()) {
            throw new RuntimeException('Asisten AI belum dikonfigurasi.');
        }

        // Diperiksa ulang tepat sebelum dikirim, bukan hanya saat admin
        // menyimpan: nama host bisa menunjuk alamat berbeda di dua waktu.
        if ($alasan = SafeOutboundUrl::reject($setting->endpoint)) {
            throw new RuntimeException("Endpoint tidak aman: {$alasan}");
        }

        $messages = $this->buildMessages($setting, $message, $history);

        [$url, $headers, $payload] = $setting->provider === AiSetting::PROVIDER_ANTHROPIC
            ? $this->anthropicRequest($setting, $messages)
            : $this->openAiRequest($setting, $messages);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT_SECONDS)
                // Satu percobaan ulang untuk galat jaringan sesaat. Tidak lebih:
                // setiap percobaan berbiaya uang dan menambah waktu tunggu
                // peserta yang sedang menatap indikator memuat.
                ->retry(2, 500, throw: false)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Tidak bisa menghubungi provider AI. Coba lagi sebentar.');
        }

        if ($response->failed()) {
            // Yang dicatat hanya status dan potongan pesan galat. Payload tidak
            // pernah ikut dicatat karena header-nya memuat kunci API - log
            // adalah tempat kunci paling sering bocor tanpa disadari.
            Log::warning('AI provider menolak permintaan', [
                'provider' => $setting->provider,
                'status' => $response->status(),
                'error' => mb_substr((string) $response->body(), 0, 300),
            ]);

            throw new RuntimeException($this->humaniseError($response->status()));
        }

        return $setting->provider === AiSetting::PROVIDER_ANTHROPIC
            ? $this->parseAnthropic($response->json(), $setting)
            : $this->parseOpenAi($response->json(), $setting);
    }

    /**
     * Riwayat dipotong di server, bukan dipercayakan ke klien.
     *
     * Klien bisa mengirim riwayat sepanjang apa pun, dan panjang riwayat
     * berbanding lurus dengan biaya tiap permintaan. Batasnya juga selalu genap
     * supaya potongan tidak mulai dari jawaban asisten tanpa pertanyaannya.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(AiSetting $setting, string $message, array $history): array
    {
        $limit = max(0, $setting->history_limit);
        $limit -= $limit % 2;

        $clean = [];

        foreach ($history as $turn) {
            $role = $turn['role'] ?? null;
            $content = trim((string) ($turn['content'] ?? ''));

            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $clean[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, self::MAX_MESSAGE_LENGTH),
            ];
        }

        $clean = $limit > 0 ? array_slice($clean, -$limit) : [];

        $clean[] = ['role' => 'user', 'content' => mb_substr($message, 0, self::MAX_MESSAGE_LENGTH)];

        return $clean;
    }

    /**
     * OpenAI-compatible: persona masuk sebagai pesan pertama berperan system.
     * Bentuk ini dipakai OpenRouter, Groq, Together, DeepSeek, vLLM, dan Azure.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function openAiRequest(AiSetting $setting, array $messages): array
    {
        $url = rtrim($setting->endpoint, '/').'/chat/completions';

        $payload = [
            'model' => $setting->model,
            'messages' => array_merge(
                filled($setting->system_prompt)
                    ? [['role' => 'system', 'content' => $setting->system_prompt]]
                    : [],
                $messages,
            ),
            'max_tokens' => $setting->max_tokens,
            'temperature' => $setting->temperature(),
        ];

        return [$url, [
            'Authorization' => 'Bearer '.$setting->api_key,
            'Content-Type' => 'application/json',
        ], $payload];
    }

    /**
     * Anthropic: persona masuk sebagai field `system` terpisah, bukan sebagai
     * pesan di dalam `messages` - mengirimnya sebagai pesan akan ditolak.
     * Autentikasinya lewat x-api-key, bukan Authorization: Bearer.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function anthropicRequest(AiSetting $setting, array $messages): array
    {
        $url = rtrim($setting->endpoint, '/').'/v1/messages';

        $payload = [
            'model' => $setting->model,
            'max_tokens' => $setting->max_tokens,
            'messages' => $messages,
        ];

        if (filled($setting->system_prompt)) {
            $payload['system'] = $setting->system_prompt;
        }

        return [$url, [
            'x-api-key' => $setting->api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ], $payload];
    }

    /** @return array{reply: string, model: string, usage: array<string, int>} */
    private function parseOpenAi(?array $body, AiSetting $setting): array
    {
        $reply = trim((string) ($body['choices'][0]['message']['content'] ?? ''));

        if ($reply === '') {
            throw new RuntimeException('Provider AI mengirim jawaban kosong.');
        }

        return [
            'reply' => $reply,
            'model' => (string) ($body['model'] ?? $setting->model),
            'usage' => [
                'input_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    /** @return array{reply: string, model: string, usage: array<string, int>} */
    private function parseAnthropic(?array $body, AiSetting $setting): array
    {
        // Respons Anthropic berupa daftar blok konten; blok bertipe text
        // digabung, tipe lain (mis. thinking) diabaikan.
        $reply = trim(implode('', array_map(
            fn ($block) => ($block['type'] ?? null) === 'text' ? (string) ($block['text'] ?? '') : '',
            $body['content'] ?? [],
        )));

        if ($reply === '') {
            // stop_reason "refusal" berarti permintaannya ditolak klasifikator,
            // bukan kerusakan - dan itu perlu dibedakan supaya pesannya masuk akal.
            if (($body['stop_reason'] ?? null) === 'refusal') {
                throw new RuntimeException('Pertanyaan itu ditolak oleh penyaring keamanan provider. Coba tanyakan dengan cara lain.');
            }

            throw new RuntimeException('Provider AI mengirim jawaban kosong.');
        }

        return [
            'reply' => $reply,
            'model' => (string) ($body['model'] ?? $setting->model),
            'usage' => [
                'input_tokens' => (int) ($body['usage']['input_tokens'] ?? 0),
                'output_tokens' => (int) ($body['usage']['output_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Galat provider diterjemahkan ke pesan yang berguna bagi peserta, tanpa
     * membocorkan endpoint, nama provider, atau isi galat aslinya.
     */
    private function humaniseError(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'Asisten AI belum bisa dipakai. Hubungi admin.',
            $status === 429 => 'Asisten AI sedang ramai. Coba lagi sebentar.',
            $status >= 500 => 'Layanan AI sedang bermasalah. Coba lagi sebentar.',
            default => 'Asisten AI gagal menjawab. Coba lagi sebentar.',
        };
    }
}
