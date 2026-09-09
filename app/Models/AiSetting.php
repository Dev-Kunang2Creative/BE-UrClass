<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Konfigurasi asisten AI. Selalu satu baris.
 *
 * Aturan keamanannya diberlakukan di sini, bukan di controller, supaya tidak ada
 * jalur yang bisa melewatinya:
 *
 *   - `encrypted` pada api_key: yang tersimpan di kolom bukan kunci aslinya.
 *   - `$hidden` pada api_key: kalau model ini pernah ikut ter-serialize ke JSON
 *     - sengaja atau karena kelalaian - kuncinya tidak ikut. Ini jaring pengaman
 *     kedua; jaring pertamanya adalah controller yang tidak pernah mengirimnya.
 */
class AiSetting extends Model
{
    use HasUlids;

    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';
    public const PROVIDER_ANTHROPIC = 'anthropic';

    public const PROVIDERS = [
        self::PROVIDER_OPENAI_COMPATIBLE,
        self::PROVIDER_ANTHROPIC,
    ];

    protected $fillable = [
        'provider',
        'endpoint',
        'api_key',
        'model',
        'model_multipliers',
        'system_prompt',
        'max_tokens',
        'temperature_x100',
        'price_input_per_mtok',
        'price_output_per_mtok',
        'price_cached_per_mtok',
        'daily_message_limit',
        'history_limit',
        'is_active',
    ];

    protected $casts = [
        // Kuncinya APP_KEY. Kalau APP_KEY berganti, kunci dan endpoint lama tidak bisa
        // didekripsi lagi dan harus diisi ulang - itu memang perilaku yang
        // diinginkan, bukan kerusakan.
        'api_key' => 'encrypted',
        'endpoint' => 'encrypted',
        'is_active' => 'boolean',
        'max_tokens' => 'integer',
        'temperature_x100' => 'integer',
        'model_multipliers' => 'array',
        'price_input_per_mtok' => 'float',
        'price_output_per_mtok' => 'float',
        'price_cached_per_mtok' => 'float',
        'daily_message_limit' => 'integer',
        'history_limit' => 'integer',
    ];

    /**
     * Tidak pernah ikut ter-serialize. Endpoint sengaja ikut disembunyikan:
     * ia bisa menunjuk gateway internal, dan peserta tidak punya alasan
     * mengetahuinya.
     */
    protected $hidden = [
        'api_key',
        'endpoint',
    ];

    /** Baris tunggalnya, dibuat kalau belum ada. */
    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }

    public function temperature(): float
    {
        return $this->temperature_x100 / 100;
    }

    /**
     * Bentuk tersamar untuk panel admin: cukup untuk memastikan kunci mana yang
     * terpasang, tidak cukup untuk dipakai.
     *
     * Awalannya berguna karena itulah yang membedakan kunci OpenRouter dari
     * kunci Anthropic; empat karakter terakhir berguna untuk mencocokkan dengan
     * catatan admin sendiri. Yang di tengah tidak pernah keluar.
     */
    public function maskedApiKey(): ?string
    {
        $key = $this->api_key;

        if (! $key) {
            return null;
        }

        if (mb_strlen($key) <= 12) {
            // Kunci pendek tidak bisa disamarkan tanpa membocorkan porsi besar
            // isinya, jadi tidak dibocorkan sama sekali.
            return str_repeat('•', 12);
        }

        return mb_substr($key, 0, 6).'…'.mb_substr($key, -4);
    }

    /**
     * Bentuk tersamar untuk panel admin: cukup untuk memastikan host/domain mana
     * yang terpasang tanpa membocorkan subdomain atau path rahasia.
     */
    public function maskedEndpoint(): ?string
    {
        $url = $this->endpoint;

        if (! $url) {
            return null;
        }

        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return str_repeat('•', 12);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];

        // Hanya awalan hostnya, tanpa ekor dan tanpa path.
        //
        // Sebelumnya bentuknya "https://band….xyz/v1" - ekor dan path ikut
        // ditampilkan. Itu memberi lebih banyak daripada yang dibutuhkan
        // tampilan ini: gunanya cuma satu, memastikan endpoint yang tersimpan
        // adalah yang dimaksud, dan awalan host sudah cukup untuk itu. TLD dan
        // path juga bagian yang paling mudah ditebak dari sebuah endpoint, jadi
        // menampilkannya mendekatkan bentuk tersamar ini ke nilai aslinya tanpa
        // menambah kegunaan.
        $maskedHost = mb_strlen($host) <= 4
            ? str_repeat('•', mb_strlen($host))
            : mb_substr($host, 0, 4).'…';

        return "{$scheme}://{$maskedHost}";
    }

    /**
     * Pengali token untuk sebuah model.
     *
     * Sebagian gateway menghitung token model tertentu lebih dari sekali
     * terhadap kuota. Pencocokannya tidak peduli besar-kecil huruf dan menerima
     * awalan, karena id model sering membawa awalan penyedia
     * ("moonshot/kimi-k3") sementara admin menuliskannya tanpa awalan.
     *
     * Bawaannya 1: model yang tidak terdaftar dihitung apa adanya. Itu arah
     * kesalahan yang aman - melaporkan token lebih sedikit daripada kenyataan
     * masih lebih baik daripada mengalikan yang seharusnya tidak.
     */
    public function multiplierFor(?string $model): float
    {
        $peta = $this->model_multipliers;

        if (! is_array($peta) || $peta === [] || ! filled($model)) {
            return 1.0;
        }

        $model = mb_strtolower(trim($model));

        foreach ($peta as $pola => $pengali) {
            $pola = mb_strtolower(trim((string) $pola));

            if ($pola === '' || ! is_numeric($pengali)) {
                continue;
            }

            if ($model === $pola || str_contains($model, $pola)) {
                return max(0.01, (float) $pengali);
            }
        }

        return 1.0;
    }

    /** Sudah cukup lengkap untuk dipakai? */
    public function isUsable(): bool
    {
        return $this->is_active
            && filled($this->endpoint)
            && filled($this->api_key)
            && filled($this->model);
    }
}
