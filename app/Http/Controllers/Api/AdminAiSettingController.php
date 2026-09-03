<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProviderException;
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
            'endpoint' => ['nullable', 'string', 'max:255'],
            // nullable, dan itu bukan kelalaian: form mengirim kolom kunci dalam
            // keadaan kosong ketika admin tidak ingin menggantinya.
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['required', 'string', 'max:120', 'regex:/^[^\<\>\s]+$/'],
            'system_prompt' => ['required', 'string', 'max:20000'],
            'max_tokens' => ['required', 'integer', 'min:256', 'max:32000'],
            'temperature_x100' => ['required', 'integer', 'min:0', 'max:200'],
            // Harga per satu juta token, USD. Dipakai untuk estimasi biaya di
            // pemantauan dan dibekukan ke tiap baris log saat permintaan
            // terjadi - jadi mengubahnya di sini tidak mengubah biaya yang
            // sudah tercatat.
            'price_input_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999'],
            'price_output_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999'],
            'price_cached_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999'],
            'daily_message_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'history_limit' => ['required', 'integer', 'min:0', 'max:40'],
            'is_active' => ['required', 'boolean'],
        ], [
            'model.regex' => 'Nama model tidak boleh mengandung spasi atau tag HTML.',
            'system_prompt.required' => 'Persona wajib diisi. Tanpa persona, asisten tidak punya panduan menjawab.',
            'system_prompt.max' => 'Persona maksimal 20.000 karakter.',
        ]);

        $endpointBaru = trim((string) ($validated['endpoint'] ?? ''));
        unset($validated['endpoint']);

        // Endpoint hanya ditulis kalau admin mengirim yang baru dan bukan mask.
        if ($endpointBaru !== '' && ! str_contains($endpointBaru, '…')) {
            if ($alasan = SafeOutboundUrl::reject($endpointBaru)) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => ['endpoint' => [$alasan]],
                ], 422);
            }
            $validated['endpoint'] = $endpointBaru;
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

        // Menyalakan tanpa endpoint atau kunci hanya menghasilkan asisten yang gagal di setiap
        // pesan, jadi ditolak di sini alih-alih dibiarkan gagal saat dipakai.
        $akanAktif = (bool) $validated['is_active'];
        $adaEndpoint = array_key_exists('endpoint', $validated) || filled($setting->endpoint);
        $adaKunci = array_key_exists('api_key', $validated) || filled($setting->api_key);

        if ($akanAktif && ! $adaEndpoint) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => ['endpoint' => ['Isi endpoint dulu sebelum mengaktifkan asisten.']],
            ], 422);
        }

        if ($akanAktif && ! $adaKunci) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => ['api_key' => ['Isi API key dulu sebelum mengaktifkan asisten.']],
            ], 422);
        }

        $setting->update($validated);

        // Yang dicatat: apa yang berubah, bukan nilainya. Log adalah tempat
        // kunci dan endpoint paling sering bocor tanpa disadari.
        AuditLogger::log(
            'AiSetting',
            'update',
            sprintf(
                'Pengaturan AI diubah: provider %s, model %s, %s%s%s',
                $setting->provider,
                $setting->model,
                $setting->is_active ? 'aktif' : 'nonaktif',
                array_key_exists('endpoint', $validated) ? ', endpoint diganti' : '',
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
     * Menguji **apa yang sedang ada di form**, bukan yang sudah tersimpan.
     * Sebelumnya ia memakai baris tersimpan, sehingga admin yang mengetik kunci
     * lalu langsung menekan uji justru menguji kunci lama - dan menerima galat
     * 401 yang tampak seperti kunci barunya salah. Tombol yang berdiri di
     * samping kolom yang belum tersimpan harus menguji isi kolom itu.
     */
    public function test(Request $request, AiChatService $chat): JsonResponse
    {
        $probe = $this->probeFromRequest($request);

        if ($probe instanceof JsonResponse) {
            return $probe;
        }

        // Diuji apa adanya walau is_active masih false: justru itu gunanya -
        // memastikan konfigurasinya jalan sebelum dinyalakan untuk peserta.
        $probe->is_active = true;
        $probe->max_tokens = min($probe->max_tokens ?: 256, 256);

        try {
            $hasil = $chat->send($probe, 'Balas dengan satu kata: OK');
        } catch (AiProviderException $e) {
            // Admin melihat status dan potongan galat aslinya - itulah yang
            // dicari orang yang sedang mendiagnosis. Kuncinya disensor lebih
            // dulu, karena badan galat provider sering meng-echo kunci yang
            // dikirim.
            return response()->json([
                'message' => $e->status()
                    ? "Provider menolak dengan status {$e->status()}."
                    : $e->getMessage(),
                'detail' => $e->detail($probe->api_key),
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Uji koneksi gagal: '.$e->getMessage()], 422);
        }

        AuditLogger::log('AiSetting', 'test', 'Uji koneksi AI berhasil', $request->user());

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
     * Daftar model dari provider, supaya admin memilih alih-alih menghafal id.
     *
     * Memakai isi form dengan alasan yang sama seperti uji koneksi: daftarnya
     * dibutuhkan justru saat kredensialnya baru diketik dan belum disimpan.
     */
    public function models(Request $request, AiChatService $chat): JsonResponse
    {
        $probe = $this->probeFromRequest($request, requireModel: false);

        if ($probe instanceof JsonResponse) {
            return $probe;
        }

        try {
            $models = $chat->listModels($probe);
        } catch (AiProviderException $e) {
            return response()->json([
                'message' => $e->status()
                    ? "Provider menolak dengan status {$e->status()}."
                    : $e->getMessage(),
                'detail' => $e->detail($probe->api_key),
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Gagal memuat daftar model: '.$e->getMessage()], 422);
        }

        return response()->json([
            'message' => count($models).' model ditemukan.',
            'data' => $models,
        ]);
    }

    /**
     * Menyusun pengaturan sementara dari isi form, jatuh kembali ke yang
     * tersimpan untuk kolom yang tidak dikirim.
     *
     * Tidak pernah disimpan - hanya dipakai untuk satu permintaan keluar. Jadi
     * admin bisa menguji kredensial sebelum menyimpannya, dan kredensial yang
     * ternyata salah tidak pernah masuk database.
     *
     * @return AiSetting|JsonResponse
     */
    private function probeFromRequest(Request $request, bool $requireModel = true)
    {
        $validated = $request->validate([
            'provider' => ['nullable', Rule::in(AiSetting::PROVIDERS)],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        $tersimpan = AiSetting::current();

        $probe = $tersimpan->replicate();
        $probe->provider = $validated['provider'] ?? $tersimpan->provider;
        $probe->model = filled($validated['model'] ?? null) ? trim($validated['model']) : $tersimpan->model;

        // Mask yang terkirim balik dari form bukan nilai asli; abaikan.
        $endpointInput = trim((string) ($validated['endpoint'] ?? ''));
        $endpointBaru = ($endpointInput !== '' && ! str_contains($endpointInput, '…')) ? $endpointInput : null;
        $probe->endpoint = $endpointBaru ?? $tersimpan->endpoint;

        $kunci = trim((string) ($validated['api_key'] ?? ''));
        $kunciBaru = ($kunci !== '' && ! str_contains($kunci, '…')) ? $kunci : null;

        $probe->api_key = $kunciBaru ?? $tersimpan->api_key;

        if (! filled($probe->endpoint) || ! filled($probe->api_key) || ($requireModel && ! filled($probe->model))) {
            return response()->json([
                'message' => $requireModel
                    ? 'Endpoint, API key, dan model harus terisi dulu.'
                    : 'Endpoint dan API key harus terisi dulu.',
            ], 422);
        }

        // Pemeriksaan keamanan lebih dulu: endpoint internal ditolak sebagai
        // endpoint internal, apa pun keadaan kuncinya.
        if ($alasan = SafeOutboundUrl::reject($probe->endpoint)) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => ['endpoint' => [$alasan]],
            ], 422);
        }

        // Kunci milik endpoint tertentu. Kalau endpointnya berganti dan kolom
        // kunci dibiarkan kosong, memakai kunci lama berarti mengujinya terhadap
        // provider yang sama sekali berbeda - hasilnya 401 yang tampak seperti
        // endpoint barunya salah.
        //
        // Ini jebakan nyata: antarmuka justru menganjurkan mengosongkan kolom
        // kunci ("biarkan kosong kalau tidak ingin menggantinya"), sehingga
        // admin yang berpindah provider hampir pasti melakukannya.
        if ($endpointBaru !== null && $endpointBaru !== $tersimpan->endpoint && $kunciBaru === null && filled($tersimpan->api_key)) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => ['api_key' => [
                    'Endpointnya berbeda dari yang tersimpan, jadi API key-nya harus diisi juga - kunci lama milik endpoint lama.',
                ]],
            ], 422);
        }

        return $probe;
    }

    /**
     * Bentuk yang dikirim ke panel admin. api_key dan endpoint diganti bentuk tersamar;
     * nilai aslinya tidak pernah masuk ke respons mana pun.
     *
     * @return array<string, mixed>
     */
    private function present(AiSetting $setting): array
    {
        return [
            'provider' => $setting->provider,
            'model' => $setting->model,
            'system_prompt' => $setting->system_prompt,
            'max_tokens' => $setting->max_tokens,
            'temperature_x100' => $setting->temperature_x100,
            'price_input_per_mtok' => (float) $setting->price_input_per_mtok,
            'price_output_per_mtok' => (float) $setting->price_output_per_mtok,
            'price_cached_per_mtok' => (float) $setting->price_cached_per_mtok,
            'daily_message_limit' => $setting->daily_message_limit,
            'history_limit' => $setting->history_limit,
            'is_active' => $setting->is_active,
            'has_endpoint' => filled($setting->endpoint),
            'endpoint_masked' => $setting->maskedEndpoint(),
            'has_api_key' => filled($setting->api_key),
            'api_key_masked' => $setting->maskedApiKey(),
            'providers' => AiSetting::PROVIDERS,
            'updated_at' => $setting->updated_at,
        ];
    }
}
