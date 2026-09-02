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
        'system_prompt',
        'max_tokens',
        'temperature_x100',
        'daily_message_limit',
        'history_limit',
        'is_active',
    ];

    protected $casts = [
        // Kuncinya APP_KEY. Kalau APP_KEY berganti, kunci lama tidak bisa
        // didekripsi lagi dan harus diisi ulang - itu memang perilaku yang
        // diinginkan, bukan kerusakan.
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'max_tokens' => 'integer',
        'temperature_x100' => 'integer',
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

    /** Sudah cukup lengkap untuk dipakai? */
    public function isUsable(): bool
    {
        return $this->is_active
            && filled($this->endpoint)
            && filled($this->api_key)
            && filled($this->model);
    }
}
