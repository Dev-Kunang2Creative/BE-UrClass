<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Support\AiLivePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pemakaian asisten AI yang sedang berlangsung, per pengguna.
 *
 * Beda dari halaman monitoring, yang menjawab "berapa yang sudah terpakai
 * bulan ini". Yang dijawab di sini satu pertanyaan lain: **siapa yang sedang
 * memakainya sekarang.** Itu yang dibutuhkan saat ada laporan asisten lambat,
 * saat biaya naik mendadak, atau saat satu akun dicurigai dipakai skrip.
 *
 * Node hidup satu menit sejak permintaan terakhirnya lalu hilang sendiri, dan
 * halamannya menyegarkan diri setiap beberapa detik.
 *
 * ## Dua sumber, dan mengapa keduanya perlu
 *
 * `ai_usage_logs` hanya memuat permintaan yang sudah selesai - barisnya ditulis
 * setelah jawaban tiba. Permintaan yang masih berjalan tidak ada di situ sama
 * sekali, padahal justru itu arti "sedang memakai" yang paling langsung. Jadi
 * status "menunggu jawaban" datang dari `AiLivePresence` (cache), sementara
 * angka permintaan dan tokennya datang dari tabel.
 *
 * Dua sumber berarti keduanya bisa tidak sinkron sesaat - seseorang bisa
 * tercatat menunggu padahal barisnya baru saja masuk. Itu selisih beberapa
 * ratus milidetik pada halaman yang menyegar tiap beberapa detik, dan tidak ada
 * keputusan yang berubah karenanya.
 */
class AdminAiLiveController extends Controller
{
    /**
     * Umur maksimal sebuah node, dalam menit.
     *
     * Tidak bisa dipilih, dan itu memang inti dari halaman ini: "live" berarti
     * sekarang. Jendela yang bisa disetel sampai satu jam membuat node bertahan
     * di layar lama setelah orangnya berhenti memakai - dan peta yang penuh node
     * yang sudah tidak aktif tidak lagi menjawab "siapa yang sedang memakai".
     *
     * Satu menit: cukup untuk menahan node selama satu putaran tanya-jawab yang
     * wajar, cukup pendek untuk hilang segera sesudahnya.
     */
    private const UMUR_NODE_MENIT = 1;

    /**
     * Batas jumlah baris yang dibaca untuk mencari permintaan terakhir tiap
     * pengguna.
     *
     * Agregatnya dihitung database, tapi "model apa yang terakhir dipakai" tidak
     * bisa diambil dalam kueri berkelompok yang sama tanpa fungsi window - dan
     * itu berbeda tulisannya di SQLite dan MySQL. Membaca potongan terbaru yang
     * dibatasi tegas lebih sederhana dan tetap terikat: jendelanya menit, dan
     * lima ratus baris sudah jauh di atas lalu lintas satu jam yang paling sibuk.
     */
    private const BATAS_BARIS_TERAKHIR = 500;

    public function index(Request $request): JsonResponse
    {
        $since = now()->subMinutes(self::UMUR_NODE_MENIT);

        $menunggu = AiLivePresence::menunggu();
        $nodes = $this->nodes($since, $menunggu);

        return response()->json([
            'data' => [
                'now' => now()->toIso8601String(),
                'node_ttl_minutes' => self::UMUR_NODE_MENIT,
                'nodes' => $nodes,
                'recent' => $this->recent(),
            ],
        ]);
    }

    /**
     * Satu node per pengguna.
     *
     * Termasuk pengguna yang sedang menunggu tapi belum punya baris apa pun di
     * jendela ini - permintaan pertamanya belum selesai, dan justru dia yang
     * paling perlu terlihat.
     *
     * @param  array<string, int>  $menunggu
     * @return array<int, array<string, mixed>>
     */
    private function nodes(Carbon $since, array $menunggu): array
    {
        $agregat = AiUsageLog::query()
            ->where('ai_usage_logs.created_at', '>=', $since)
            ->whereNotNull('ai_usage_logs.user_id')
            ->leftJoin('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->groupBy('ai_usage_logs.user_id', 'users.name', 'users.email', 'users.role')
            ->selectRaw('ai_usage_logs.user_id as user_id')
            ->selectRaw('users.name as name')
            ->selectRaw('users.email as email')
            ->selectRaw('users.role as role')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.cost_idr), 0) as cost_idr')
            ->selectRaw("SUM(CASE WHEN ai_usage_logs.status = 'ok' THEN 0 ELSE 1 END) as gagal")
            ->selectRaw('MAX(ai_usage_logs.created_at) as last_seen')
            ->selectRaw('AVG(ai_usage_logs.duration_ms) as avg_duration_ms')
            ->get()
            ->keyBy('user_id');

        $terakhir = $this->permintaanTerakhir($since);

        // Pengguna yang sedang menunggu tapi belum punya agregat sama sekali
        // tetap dibuatkan node - identitasnya diambil terpisah, karena tidak ada
        // baris log yang bisa di-join untuk menemukannya.
        $tanpaBaris = array_diff(array_keys($menunggu), $agregat->keys()->all());
        $identitas = $tanpaBaris === []
            ? collect()
            : DB::table('users')
                ->whereIn('id', $tanpaBaris)
                ->get(['id', 'name', 'email', 'role'])
                ->keyBy('id');

        $sekarang = now();

        $nodes = $agregat->map(function ($row) use ($menunggu, $terakhir, $sekarang) {
            $userId = (string) $row->user_id;
            $lastSeen = Carbon::parse($row->last_seen);

            // Dibulatkan ke int sekali di sini. diffInSeconds mengembalikan
            // float di Carbon 3, dan meneruskannya apa adanya membuat
            // seconds_ago keluar sebagai 0.511503 - angka yang tidak berarti apa
            // pun bagi pembacanya, dan yang memicu galat konversi implisit saat
            // dipakai sebagai int.
            // Dibulatkan ke bawah, bukan dibulatkan biasa: yang dilaporkan
            // adalah detik penuh yang sudah lewat, dan "31 detik lalu" untuk
            // sesuatu yang terjadi 30,5 detik lalu melebihkan tanpa alasan.
            $detikLalu = (int) floor($sekarang->diffInSeconds($lastSeen, true));

            return [
                'user_id' => $userId,
                'name' => $row->name ?? 'Pengguna dihapus',
                'email' => $row->email,
                'role' => $row->role,
                'requests' => (int) $row->requests,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'total_tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
                'cost_idr' => round((float) $row->cost_idr, 2),
                'failed' => (int) $row->gagal,
                'avg_duration_ms' => (int) round((float) $row->avg_duration_ms),
                'last_seen_at' => $lastSeen->toIso8601String(),
                'seconds_ago' => max(0, $detikLalu),
                'last_model' => $terakhir[$userId]['model'] ?? null,
                'last_status' => $terakhir[$userId]['status'] ?? null,
                'waiting_seconds' => $menunggu[$userId] ?? null,
                'state' => $this->state($userId, $menunggu),
            ];
        })->values();

        foreach ($tanpaBaris as $userId) {
            $user = $identitas[$userId] ?? null;

            $nodes->push([
                'user_id' => (string) $userId,
                'name' => $user->name ?? 'Pengguna dihapus',
                'email' => $user->email ?? null,
                'role' => $user->role ?? null,
                'requests' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'cost_idr' => 0.0,
                'failed' => 0,
                'avg_duration_ms' => 0,
                'last_seen_at' => null,
                'seconds_ago' => null,
                'last_model' => null,
                'last_status' => null,
                'waiting_seconds' => $menunggu[(string) $userId] ?? 0,
                'state' => 'waiting',
            ]);
        }

        // Yang sedang menunggu di depan, lalu yang paling baru aktif. Urutan ini
        // yang membuat halaman bisa dibaca sekilas tanpa mencari.
        return $nodes
            ->sortBy([
                fn ($a, $b) => ($a['state'] === 'waiting' ? 0 : 1) <=> ($b['state'] === 'waiting' ? 0 : 1),
                fn ($a, $b) => ($a['seconds_ago'] ?? PHP_INT_MAX) <=> ($b['seconds_ago'] ?? PHP_INT_MAX),
            ])
            ->values()
            ->all();
    }

    /**
     * Status satu node. Tinggal dua sejak jendelanya dipatok satu menit:
     * `waiting` untuk permintaan yang sedang berjalan, `active` untuk sisanya -
     * karena apa pun yang masih ada di jendela ini memang baru saja terjadi.
     *
     * @param  array<string, int>  $menunggu
     */
    private function state(string $userId, array $menunggu): string
    {
        return isset($menunggu[$userId]) ? 'waiting' : 'active';
    }

    /**
     * Model dan status permintaan terakhir tiap pengguna.
     *
     * @return array<string, array{model: ?string, status: string}>
     */
    private function permintaanTerakhir(Carbon $since): array
    {
        $hasil = [];

        $rows = AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->orderByDesc('created_at')
            ->limit(self::BATAS_BARIS_TERAKHIR)
            ->get(['user_id', 'model', 'status']);

        foreach ($rows as $row) {
            // Diurutkan menurun, jadi kemunculan pertama tiap pengguna adalah
            // yang terbaru.
            $hasil[(string) $row->user_id] ??= [
                'model' => $row->model,
                'status' => $row->status,
            ];
        }

        return $hasil;
    }

    /**
     * Sepuluh permintaan terakhir, dikenali dari **siapa** yang mengirimnya.
     *
     * Yang dikedepankan nama pengguna, bukan nama model. Modelnya di UrClass
     * hampir selalu satu dan sama, jadi kolom model tidak memisahkan apa pun -
     * sementara pengguna mana yang mengirim permintaan itu justru pertanyaan
     * yang dijawab halaman ini.
     *
     * Sengaja **tidak** dibatasi umur node yang satu menit itu. Node memang
     * harus hilang cepat supaya petanya tetap berarti "sekarang", tapi daftar
     * ini justru dibaca ketika tidak ada lalu lintas sama sekali - saat asisten
     * baru saja rusak, yang dicari adalah permintaan terakhir yang masuk, dari
     * siapa pun dan kapan pun itu.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(): array
    {
        return AiUsageLog::query()
            ->with('user:id,name,email')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AiUsageLog $log) => [
                'id' => $log->id,
                'name' => $log->user?->name ?? 'Pengguna dihapus',
                'email' => $log->user?->email,
                'input_tokens' => $log->input_tokens,
                'output_tokens' => $log->output_tokens,
                'status' => $log->status,
                'created_at' => $log->created_at?->toIso8601String(),
                'seconds_ago' => $log->created_at
                    ? (int) floor(now()->diffInSeconds($log->created_at, true))
                    : null,
            ])
            ->all();
    }
}
