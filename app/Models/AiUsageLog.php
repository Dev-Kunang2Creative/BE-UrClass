<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu permintaan ke asisten AI.
 *
 * Tidak memuat isi pesan maupun jawaban - hanya jumlah token, biaya, dan
 * hasilnya. Pemantauan biaya tidak butuh transkripnya, dan menyimpan transkrip
 * belajar seseorang adalah beban privasi yang tidak dibutuhkan.
 */
class AiUsageLog extends Model
{
    use HasUlids;

    /** Hanya created_at; baris ini tidak pernah diubah setelah dibuat. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'cached_tokens',
        'cost_usd',
        'status',
        'reason',
        'duration_ms',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cached_tokens' => 'integer',
        'cost_usd' => 'float',
        'duration_ms' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Biaya satu permintaan menurut harga yang berlaku saat itu.
     *
     * Token cache dihitung dengan harganya sendiri dan dikurangkan dari input,
     * karena provider melaporkan cached_tokens sebagai bagian dari input -
     * menghitung keduanya dengan harga penuh akan menggandakan biayanya.
     */
    public static function estimateCost(
        AiSetting $setting,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens,
    ): float {
        $uncachedInput = max(0, $inputTokens - $cachedTokens);

        return round(
            ($uncachedInput / 1_000_000) * (float) $setting->price_input_per_mtok
            + ($cachedTokens / 1_000_000) * (float) $setting->price_cached_per_mtok
            + ($outputTokens / 1_000_000) * (float) $setting->price_output_per_mtok,
            6,
        );
    }
}
