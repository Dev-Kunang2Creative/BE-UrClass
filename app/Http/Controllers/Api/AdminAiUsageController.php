<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pemantauan pemakaian asisten AI.
 *
 * Menjawab tiga pertanyaan yang pasti muncul begitu fitur berbiaya dinyalakan:
 * berapa yang sudah terpakai, kapan lonjakannya, dan siapa yang memakainya.
 *
 * Seluruh agregasi dilakukan database dalam kueri berkelompok - bukan dengan
 * memuat baris lalu menghitungnya di PHP. Tabel ini tumbuh terus, dan pola
 * kedua itu berhenti bekerja tepat saat pemantauannya paling dibutuhkan.
 */
class AdminAiUsageController extends Controller
{
    /** Jendela waktu yang ditawarkan, beserta lebar ember grafiknya. */
    private const WINDOWS = [
        'today' => ['label' => 'Hari ini', 'bucket' => 'hour'],
        '24h' => ['label' => '24 jam', 'bucket' => 'hour'],
        '7d' => ['label' => '7 hari', 'bucket' => 'day'],
        '30d' => ['label' => '30 hari', 'bucket' => 'day'],
        '60d' => ['label' => '60 hari', 'bucket' => 'day'],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::WINDOWS))],
        ]);

        $window = $validated['window'] ?? '24h';
        $bucket = self::WINDOWS[$window]['bucket'];
        $since = $this->since($window);

        return response()->json([
            'data' => [
                'window' => $window,
                'windows' => collect(self::WINDOWS)
                    ->map(fn ($meta, $key) => ['key' => $key, 'label' => $meta['label']])
                    ->values(),
                'since' => $since->toIso8601String(),
                'bucket' => $bucket,
                'totals' => $this->totals($since),
                'series' => $this->series($since, $bucket),
                'by_model' => $this->byModel($since),
                'top_users' => $this->topUsers($since),
                'recent' => $this->recent(),
            ],
        ]);
    }

    private function since(string $window): Carbon
    {
        return match ($window) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '60d' => now()->subDays(60),
            default => now()->subDay(),
        };
    }

    /** @return array<string, int|float> */
    private function totals(Carbon $since): array
    {
        $row = AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END), 0) as ok")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END), 0) as blocked")
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cached_tokens), 0) as cached_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->selectRaw('COALESCE(AVG(NULLIF(duration_ms, 0)), 0) as avg_duration_ms')
            ->first();

        return [
            'requests' => (int) $row->requests,
            'ok' => (int) $row->ok,
            'failed' => (int) $row->failed,
            'blocked' => (int) $row->blocked,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'cached_tokens' => (int) $row->cached_tokens,
            'cost_usd' => round((float) $row->cost_usd, 4),
            'avg_duration_ms' => (int) round((float) $row->avg_duration_ms),
        ];
    }

    /**
     * Ekspresi pengelompokan waktu yang berjalan di MySQL maupun SQLite.
     *
     * Sengaja tidak memakai DATE_FORMAT() saja: fungsi itu tidak ada di SQLite,
     * dan dua endpoint admin lain di repo ini sudah tidak bisa diuji sama sekali
     * karena memakainya. Pemantauan yang tidak bisa diuji adalah pemantauan yang
     * diam-diam rusak.
     */
    private function bucketExpression(string $bucket): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return $bucket === 'hour'
                ? "strftime('%Y-%m-%d %H:00:00', created_at)"
                : "strftime('%Y-%m-%d', created_at)";
        }

        return $bucket === 'hour'
            ? "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')"
            : "DATE_FORMAT(created_at, '%Y-%m-%d')";
    }

    /** @return array<int, array<string, mixed>> */
    private function series(Carbon $since, string $bucket): array
    {
        $expr = $this->bucketExpression($bucket);

        $rows = AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw("{$expr} as bucket")
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        // Ember kosong tetap dikirim sebagai nol. Tanpa itu, grafik menyambung
        // langsung dari jam ramai ke jam ramai berikutnya dan jeda sepinya
        // hilang - persis informasi yang dicari orang saat melihat lonjakan.
        $out = [];
        $cursor = $bucket === 'hour' ? $since->copy()->startOfHour() : $since->copy()->startOfDay();
        $end = now();

        while ($cursor <= $end) {
            $key = $cursor->format($bucket === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d');
            $row = $rows->get($key);

            $out[] = [
                'bucket' => $key,
                'requests' => (int) ($row->requests ?? 0),
                'input_tokens' => (int) ($row->input_tokens ?? 0),
                'output_tokens' => (int) ($row->output_tokens ?? 0),
                'total_tokens' => (int) ($row->input_tokens ?? 0) + (int) ($row->output_tokens ?? 0),
                'cost_usd' => round((float) ($row->cost_usd ?? 0), 6),
            ];

            $bucket === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function byModel(Carbon $since): array
    {
        return AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('model')
            ->selectRaw('model')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->groupBy('model')
            ->orderByDesc('requests')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'model' => $row->model,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function topUsers(Carbon $since): array
    {
        return AiUsageLog::query()
            ->where('ai_usage_logs.created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->leftJoin('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->selectRaw('users.name as name')
            ->selectRaw('users.email as email')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens + ai_usage_logs.output_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.cost_usd), 0) as cost_usd')
            ->groupBy('users.name', 'users.email')
            ->orderByDesc('requests')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?? 'Pengguna dihapus',
                'email' => $row->email,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])
            ->all();
    }

    /**
     * Permintaan terakhir. Tidak dibatasi jendela waktu - kalau asisten baru
     * saja rusak, yang dicari admin adalah permintaan terakhir apa pun jendela
     * yang sedang dipilih.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(): array
    {
        return AiUsageLog::query()
            ->with('user:id,name,email')
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'created_at' => $log->created_at,
                'user_name' => $log->user?->name ?? 'Pengguna dihapus',
                'model' => $log->model,
                'input_tokens' => $log->input_tokens,
                'output_tokens' => $log->output_tokens,
                'cost_usd' => round($log->cost_usd, 6),
                'status' => $log->status,
                'reason' => $log->reason,
                'duration_ms' => $log->duration_ms,
            ])
            ->all();
    }
}
