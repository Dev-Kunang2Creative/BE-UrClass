<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\TicketLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->per_page ?? 15), 100);
        $users = User::real()
            // Total token AI per pengguna, dijumlahkan database sebagai subkueri
            // pada kueri yang sama.
            //
            // Bukan dengan memuat log tiap pengguna lalu menjumlahkannya di PHP:
            // itu N+1 yang berhenti bekerja tepat saat tabelnya paling besar -
            // dan tabel ini bertambah satu baris setiap kali ada yang bertanya
            // ke asisten.
            //
            // Dijumlahkan sesudah pengali model, karena kolomnya memang menyimpan
            // hasil kali itu. Jadi angkanya sebanding dengan halaman pemantauan
            // dan dengan yang benar-benar dipotong dari kuota provider.
            ->withSum(
                'aiUsageLogs as ai_total_tokens',
                DB::raw('input_tokens + output_tokens'),
            )
            ->withCount('aiUsageLogs as ai_requests')
            ->latest()
            ->when($request->search, fn($q, $s) => $q->where(function ($searchQuery) use ($s) {
                $searchQuery->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            }))
            ->paginate($perPage);

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $user,
            // Ringkasan yang dipakai panel admin: sisa tiket, riwayat singkatnya,
            // dan pemakaian asisten AI. Ketiganya soal "apa yang sudah dan masih
            // bisa dipakai akun ini", dan mengambilnya lewat tiga permintaan
            // terpisah hanya menambah bunyi tanpa menambah apa pun.
            'meta' => [
                'tickets' => $this->ticketSummary($user),
                'ai_usage' => $this->aiUsageSummary($user),
            ],
        ]);
    }

    /**
     * Menambah atau mengurangi tiket seorang pengguna.
     *
     * Ada karena kasus yang tidak bisa diselesaikan alur pembelian: pembayaran
     * yang masuk tapi callback-nya gagal, tryout yang batal karena gangguan di
     * sisi kami, dan hadiah lomba. Tanpa jalan ini, satu-satunya pilihannya
     * adalah menyunting basis data langsung - yang tidak meninggalkan jejak
     * siapa pun.
     *
     * ## Yang dijaga di sini
     *
     * **Saldo tidak boleh minus.** Pengurangan dibatasi sebesar saldo yang ada,
     * karena saldo negatif akan membuat setiap pemeriksaan "tiket cukup?"
     * berperilaku aneh di tempat-tempat yang tidak menduganya.
     *
     * **Barisnya dikunci selama diubah.** `lockForUpdate` mencegah dua
     * penyesuaian yang bersamaan saling menimpa - dan yang lebih mungkin
     * terjadi: penyesuaian admin yang bersamaan dengan peserta yang sedang
     * memulai tryout, yang juga memotong saldo.
     *
     * **Alasan wajib diisi.** Tiket adalah uang bagi peserta, dan penyesuaian
     * tanpa alasan tidak bisa ditinjau siapa pun setelahnya - termasuk oleh yang
     * melakukannya sendiri, sebulan kemudian.
     */
    public function adjustTickets(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'min:-1000', 'max:1000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ], [
            'amount.required' => 'Isi jumlah tiketnya.',
            'amount.not_in' => 'Jumlah tiket tidak boleh nol.',
            'reason.required' => 'Tulis alasan penyesuaiannya.',
            'reason.min' => 'Alasannya terlalu singkat untuk bisa ditinjau nanti.',
        ]);

        $jumlah = (int) $validated['amount'];
        $alasan = trim($validated['reason']);

        if ($user->role === 'admin') {
            return response()->json([
                'message' => 'Akun admin tidak memakai tiket, jadi tidak perlu disesuaikan.',
            ], 422);
        }

        $hasil = DB::transaction(function () use ($user, $jumlah, $alasan) {
            $terkunci = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $sebelum = (int) $terkunci->ticket_balance;

            // Dibatasi, bukan ditolak: admin yang ingin mengosongkan saldo tidak
            // perlu tahu lebih dulu angka persisnya.
            $terpakai = $jumlah < 0 ? -min($sebelum, abs($jumlah)) : $jumlah;

            if ($terpakai === 0) {
                return ['kosong' => true, 'sebelum' => $sebelum, 'sesudah' => $sebelum, 'terpakai' => 0];
            }

            $terkunci->ticket_balance = $sebelum + $terpakai;
            $terkunci->save();

            TicketLog::create([
                'user_id' => $terkunci->id,
                'type' => $terpakai > 0 ? 'credit' : 'debit',
                'amount' => abs($terpakai),
                // Sumbernya dibedakan dari 'paket' dan 'tryout' supaya
                // penyesuaian tangan bisa dipisahkan dari alur normal saat
                // riwayatnya ditinjau.
                'source' => 'admin',
                'description' => $alasan,
            ]);

            return [
                'kosong' => false,
                'sebelum' => $sebelum,
                'sesudah' => (int) $terkunci->ticket_balance,
                'terpakai' => $terpakai,
            ];
        });

        if ($hasil['kosong']) {
            return response()->json([
                'message' => 'Saldo tiketnya sudah nol, tidak ada yang bisa dikurangi.',
            ], 422);
        }

        AuditLogger::log(
            'Pengguna',
            $hasil['terpakai'] > 0 ? 'tambah_tiket' : 'kurang_tiket',
            sprintf(
                '%s %d tiket %s %s (%d -> %d). Alasan: %s',
                $hasil['terpakai'] > 0 ? 'Menambah' : 'Mengurangi',
                abs($hasil['terpakai']),
                $hasil['terpakai'] > 0 ? 'ke' : 'dari',
                $user->email,
                $hasil['sebelum'],
                $hasil['sesudah'],
                $alasan,
            ),
            $request->user(),
            $user,
        );

        return response()->json([
            'message' => sprintf(
                '%s %d tiket. Saldo sekarang %d.',
                $hasil['terpakai'] > 0 ? 'Berhasil menambah' : 'Berhasil mengurangi',
                abs($hasil['terpakai']),
                $hasil['sesudah'],
            ),
            'data' => [
                'ticket_balance' => $hasil['sesudah'],
                'tickets' => $this->ticketSummary($user->refresh()),
            ],
        ]);
    }

    /**
     * Saldo tiket beserta riwayat singkatnya.
     *
     * @return array<string, mixed>
     */
    private function ticketSummary(User $user): array
    {
        $agregat = TicketLog::where('user_id', $user->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as keluar")
            ->first();

        return [
            'balance' => (int) $user->ticket_balance,
            'total_credited' => (int) ($agregat->masuk ?? 0),
            'total_debited' => (int) ($agregat->keluar ?? 0),
            'recent' => TicketLog::where('user_id', $user->id)
                ->latest()
                ->limit(8)
                ->get(['id', 'type', 'amount', 'source', 'description', 'created_at'])
                ->map(fn (TicketLog $log) => [
                    'id' => $log->id,
                    'type' => $log->type,
                    'amount' => (int) $log->amount,
                    'source' => $log->source,
                    'description' => $log->description,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Pemakaian asisten AI oleh satu pengguna.
     *
     * Token dijumlahkan **sesudah** pengali model diterapkan - kolomnya memang
     * menyimpan hasil kali itu - jadi angkanya sebanding dengan yang dilihat di
     * halaman pemantauan, dan dengan yang benar-benar dipotong dari kuota
     * provider.
     *
     * Dihitung database dalam satu kueri berkelompok. Memuat barisnya lalu
     * menjumlahkan di PHP berhenti bekerja tepat saat orang yang paling banyak
     * memakai adalah yang paling ingin diperiksa.
     *
     * @return array<string, mixed>
     */
    private function aiUsageSummary(User $user): array
    {
        $total = AiUsageLog::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cached_tokens), 0) as cached_tokens')
            ->selectRaw('COALESCE(SUM(cost_idr), 0) as cost_idr')
            ->selectRaw("SUM(CASE WHEN status = 'ok' THEN 0 ELSE 1 END) as gagal")
            ->selectRaw('MAX(created_at) as last_used_at')
            ->first();

        $hariIni = AiUsageLog::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->where('status', 'ok')
            ->count();

        return [
            'requests' => (int) ($total->requests ?? 0),
            'input_tokens' => (int) ($total->input_tokens ?? 0),
            'output_tokens' => (int) ($total->output_tokens ?? 0),
            'cached_tokens' => (int) ($total->cached_tokens ?? 0),
            'total_tokens' => (int) ($total->input_tokens ?? 0) + (int) ($total->output_tokens ?? 0),
            'cost_idr' => round((float) ($total->cost_idr ?? 0), 2),
            'failed' => (int) ($total->gagal ?? 0),
            'used_today' => $hariIni,
            'last_used_at' => $total->last_used_at
                ? \Illuminate\Support\Carbon::parse($total->last_used_at)->toIso8601String()
                : null,
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $users = User::real()->where('role', 'user')
            ->when($request->search, fn($q, $s) =>
                $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
            )
            ->latest()
            ->get(['name', 'email', 'phone_number', 'school_origin', 'grade_level', 'created_at']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pengguna');

        $headers = ['No', 'Nama', 'Email', 'No. HP', 'Asal Sekolah', 'Kelas', 'Tanggal Daftar'];
        $sheet->fromArray($headers, null, 'A1');

        $headerStyle = [
            'font'    => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF004AAB']],
        ];
        $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

        foreach ($users as $i => $user) {
            $sheet->fromArray([
                $i + 1,
                $user->name,
                $user->email,
                $user->phone_number ?? '-',
                $user->school_origin ?? '-',
                $user->grade_level ?? '-',
                $user->created_at->format('d/m/Y'),
            ], null, 'A' . ($i + 2));
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'data-pengguna-' . now()->format('Ymd-His') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->stream(
            fn() => $writer->save('php://output'),
            200,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'Anda tidak dapat menghapus akun Anda sendiri.'
            ], 403);
        }

        if ($user->role === 'admin') {
            return response()->json([
                'message' => 'Akun admin tidak dapat dihapus.'
            ], 403);
        }

        $user->tokens()->delete();

        $user->delete();

        return response()->json([
            'message' => 'Data pengguna berhasil dihapus oleh Admin.'
        ]);
    }
}
