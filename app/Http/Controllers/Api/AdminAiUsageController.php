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
    /**
     * Jendela waktu yang ditawarkan, beserta lebar ember bawaannya.
     *
     * `hourly` menyatakan apakah jendela itu boleh dilihat per jam. Dibatasi
     * sampai tujuh hari (168 titik) karena di atas itu payload-nya membengkak
     * tanpa menambah apa pun yang bisa dibaca - 30 hari per jam berarti 720
     * titik, dan grafik sepanjang itu lebih cepat dijawab dengan mengganti
     * jendelanya daripada digulir.
     */
    private const WINDOWS = [
        'today' => ['label' => 'Hari ini', 'bucket' => 'hour', 'hourly' => true],
        '24h' => ['label' => '24 jam', 'bucket' => 'hour', 'hourly' => true],
        '7d' => ['label' => '7 hari', 'bucket' => 'day', 'hourly' => true],
        '30d' => ['label' => '30 hari', 'bucket' => 'day', 'hourly' => false],
        '60d' => ['label' => '60 hari', 'bucket' => 'day', 'hourly' => false],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::WINDOWS))],
            'bucket' => ['nullable', 'string', 'in:hour,day'],
        ]);

        $window = $validated['window'] ?? '24h';
        $meta = self::WINDOWS[$window];
        $diminta = $validated['bucket'] ?? null;

        // Permintaan per jam hanya dilayani untuk jendela yang mengizinkannya.
        // Diam-diam turun ke harian, bukan menolak: antarmuka bisa masih
        // mengirim pilihan lama saat jendelanya berganti, dan menolaknya berarti
        // grafik kosong tanpa sebab yang terlihat.
        $bucket = match (true) {
            $diminta === 'hour' && $meta['hourly'] => 'hour',
            $diminta === 'day' => 'day',
            default => $meta['bucket'],
        };

        $since = $this->since($window);

        return response()->json([
            'data' => [
                'window' => $window,
                'windows' => collect(self::WINDOWS)
                    ->map(fn ($item, $key) => [
                        'key' => $key,
                        'label' => $item['label'],
                        'hourly' => $item['hourly'],
                    ])
                    ->values(),
                'since' => $since->toIso8601String(),
                'bucket' => $bucket,
                'totals' => $this->totals($since),
                'series' => $series = $this->series($since, $bucket),
                // Puncaknya dihitung di sini supaya angka yang dipakai grafik dan
                // angka yang ditulis sebagai "puncak" tidak mungkin berbeda.
                'peak' => $this->peak($series),
                'top_users' => $this->topUsers($since),
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
            ->selectRaw('COALESCE(SUM(cost_idr), 0) as cost_idr')
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
            'cost_idr' => round((float) $row->cost_idr, 2),
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
            ->selectRaw('COALESCE(SUM(cost_idr), 0) as cost_idr')
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
                'cost_idr' => round((float) ($row->cost_idr ?? 0), 2),
            ];

            $bucket === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return $out;
    }

    /**
     * Ember dengan token terbanyak, atau null kalau tidak ada pemakaian.
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return array<string, mixed>|null
     */
    private function peak(array $series): ?array
    {
        $puncak = null;

        foreach ($series as $titik) {
            if ($titik['total_tokens'] > 0 && ($puncak === null || $titik['total_tokens'] > $puncak['total_tokens'])) {
                $puncak = $titik;
            }
        }

        return $puncak;
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
            ->selectRaw('COALESCE(SUM(ai_usage_logs.cost_idr), 0) as cost_idr')
            ->groupBy('users.name', 'users.email')
            ->orderByDesc('requests')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?? 'Pengguna dihapus',
                'email' => $row->email,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'cost_idr' => round((float) $row->cost_idr, 2),
            ])
            ->all();
    }

}
