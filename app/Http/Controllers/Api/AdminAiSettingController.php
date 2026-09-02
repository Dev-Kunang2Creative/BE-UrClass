<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\AiChatService;
use App\Services\AuditLogger;
use App\Support\SafeOutboundUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Konfigurasi asisten AI dari panel admin.
 *
 * Aturan yang tidak boleh dilanggar controller ini: **kunci API tidak pernah
 * keluar**, bahkan ke admin yang baru saja memasukkannya. Yang dikirim balik
 * hanya bentuk tersamar, cukup untuk memastikan kunci mana yang terpasang.
 *
 * Alasannya bukan ketidakpercayaan pada admin: begitu sebuah nilai dikirim ke
 * browser, ia ada di riwayat tab jaringan, di cache, dan di setiap ekstensi yang
 * bisa membaca respons. Kunci yang pernah sampai ke sana harus dianggap bocor.
 */
class AdminAiSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $setting = AiSetting::current();

        return response()->json(['data' => $this->present($setting)]);
    }

    public function update(Request $request): JsonResponse
    {
        $setting = AiSetting::current();

        $validated = $request->validate([
            'provider' => ['required', Rule::in(AiSetting::PROVIDERS)],
            'endpoint' => ['required', 'string', 'max:255'],
            // nullable, dan itu bukan kelalaian: form mengirim kolom kunci dalam
            // keadaan kosong ketika admin tidak ingin menggantinya.
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['required', 'string', 'max:120', 'regex:/^[^\<\>\s]+$/'],
            'system_prompt' => ['required', 'string', 'max:20000'],
            'max_tokens' => ['required', 'integer', 'min:256', 'max:32000'],
            'temperature_x100' => ['required', 'integer', 'min:0', 'max:200'],
            'daily_message_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'history_limit' => ['required', 'integer', 'min:0', 'max:40'],
            'is_active' => ['required', 'boolean'],
        ], [
            'model.regex' => 'Nama model tidak boleh mengandung spasi atau tag HTML.',
            'system_prompt.required' => 'Persona wajib diisi. Tanpa persona, asisten tidak punya panduan menjawab.',
            'system_prompt.max' => 'Persona maksimal 20.000 karakter.',
        ]);

        if ($alasan = SafeOutboundUrl::reject($validated['endpoint'])) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => ['endpoint' => [$alasan]],
            ], 422);
        }

        $kunciBaru = trim((string) ($validated['api_key'] ?? ''));
        unset($validated['api_key']);

        // Kunci hanya ditulis kalau admin benar-benar mengirim yang baru. Kalau
        // tidak, yang lama dipertahankan.
        //
        // Ini yang mencegah kesalahan paling mudah terjadi di layar seperti ini:
        // form menampilkan kunci tersamar, admin menyimpan tanpa menyentuhnya,
        // dan tanpa penjagaan ini justru masknya yang tersimpan sebagai kunci -
        // asisten lalu mati dengan galat 401 yang tidak jelas sebabnya.
        if ($kunciBaru !== '' && ! str_contains($kunciBaru, '…')) {
            $validated['api_key'] = $kunciBaru;
        }

        // Menyalakan tanpa kunci hanya menghasilkan asisten yang gagal di setiap
        // pesan, jadi ditolak di sini alih-alih dibiarkan gagal saat dipakai.
        $akanAktif = (bool) $validated['is_active'];
        $adaKunci = array_key_exists('api_key', $validated) || filled($setting->api_key);

        if ($akanAktif && ! $adaKunci) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => ['api_key' => ['Isi API key dulu sebelum mengaktifkan asisten.']],
            ], 422);
        }

        $setting->update($validated);

        // Yang dicatat: apa yang berubah, bukan nilainya. Log adalah tempat
        // kunci paling sering bocor tanpa disadari.
        AuditLogger::log(
            'AiSetting',
            'update',
            sprintf(
                'Pengaturan AI diubah: provider %s, model %s, %s%s',
                $setting->provider,
                $setting->model,
                $setting->is_active ? 'aktif' : 'nonaktif',
                array_key_exists('api_key', $validated) ? ', API key diganti' : '',
            ),
            $request->user(),
            $setting,
        );

        return response()->json([
            'message' => 'Pengaturan AI berhasil disimpan.',
            'data' => $this->present($setting->fresh()),
        ]);
    }

    /**
     * Uji koneksi ke provider dengan satu pesan pendek.
     *
     * Ada supaya admin tidak perlu menebak apakah kredensialnya benar dengan
     * membuka jendela chat sebagai peserta - dan supaya galatnya bisa
     * ditampilkan apa adanya di sini, di layar yang hanya dilihat admin.
     */
    public function test(Request $request, AiChatService $chat): JsonResponse
    {
        $setting = AiSetting::current();

        if (! filled($setting->endpoint) || ! filled($setting->api_key) || ! filled($setting->model)) {
            return response()->json([
                'message' => 'Endpoint, API key, dan model harus terisi dulu.',
            ], 422);
        }

        // Diuji apa adanya walau is_active masih false: justru itu gunanya -
        // memastikan konfigurasinya jalan sebelum dinyalakan untuk peserta.
        $probe = $setting->replicate();
        $probe->is_active = true;
        $probe->max_tokens = min($setting->max_tokens, 256);

        try {
            $hasil = $chat->send($probe, 'Balas dengan satu kata: OK');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Uji koneksi gagal: '.$e->getMessage()], 422);
        }

        AuditLogger::log('AiSetting', 'test', 'Uji koneksi AI berhasil', $request->user(), $setting);

        return response()->json([
            'message' => 'Koneksi berhasil.',
            'data' => [
                'reply' => mb_substr($hasil['reply'], 0, 200),
                'model' => $hasil['model'],
                'usage' => $hasil['usage'],
            ],
        ]);
    }

    /**
     * Bentuk yang dikirim ke panel admin. api_key diganti bentuk tersamar;
     * nilai aslinya tidak pernah masuk ke respons mana pun.
     *
     * @return array<string, mixed>
     */
    private function present(AiSetting $setting): array
    {
        return [
            'provider' => $setting->provider,
            'endpoint' => $setting->endpoint,
            'model' => $setting->model,
            'system_prompt' => $setting->system_prompt,
            'max_tokens' => $setting->max_tokens,
            'temperature_x100' => $setting->temperature_x100,
            'daily_message_limit' => $setting->daily_message_limit,
            'history_limit' => $setting->history_limit,
            'is_active' => $setting->is_active,
            'has_api_key' => filled($setting->api_key),
            'api_key_masked' => $setting->maskedApiKey(),
            'providers' => AiSetting::PROVIDERS,
            'updated_at' => $setting->updated_at,
        ];
    }
}
