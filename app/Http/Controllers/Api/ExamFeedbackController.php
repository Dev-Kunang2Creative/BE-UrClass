<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamFeedback;
use App\Models\Tryout;
use App\Models\TryoutSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamFeedbackController extends Controller
{
    public function store(Request $request, Tryout $tryout): JsonResponse
    {
        abort_unless(TryoutSession::where('user_id', $request->user()->id)
            ->where('tryout_id', $tryout->id)->where('status', 'finished')->exists(),
            403, 'Selesaikan tryout sebelum mengirim masukan.');
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'category' => ['required', Rule::in(ExamFeedback::CATEGORIES)],
            'comment' => ['required', 'string', 'max:5000'],
        ]);
        $masukan = ExamFeedback::updateOrCreate([
            'user_id' => $request->user()->id, 'tryout_id' => $tryout->id,
        ], $data);

        return response()->json(['message' => 'Terima kasih atas masukanmu.', 'data' => $masukan],
            $masukan->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $filter = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'tryout_id' => ['nullable', 'ulid'],
            'category' => ['nullable', Rule::in(ExamFeedback::CATEGORIES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'export' => ['nullable', 'in:csv'],
        ]);
        $query = ExamFeedback::with(['user:id,name', 'tryout:id,title'])->latest('created_at')->orderByDesc('id');
        foreach (['rating', 'tryout_id', 'category'] as $kolom) {
            if (! empty($filter[$kolom])) {
                $query->where($kolom, $filter[$kolom]);
            }
        }
        if (! empty($filter['search'])) {
            $search = '%'.$filter['search'].'%';
            $query->where(fn ($q) => $q->where('comment', 'like', $search)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $search))
                ->orWhereHas('tryout', fn ($t) => $t->where('title', 'like', $search)));
        }
        if (($filter['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query) {
                $output = fopen('php://output', 'w');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['Tanggal', 'Peserta', 'Tryout', 'Rating', 'Kategori', 'Komentar'], ',', '"', '');
                foreach ($query->lazy(200) as $masukan) {
                    $baris = [$masukan->created_at->toIso8601String(), $masukan->user?->name ?? 'Akun dihapus',
                        $masukan->tryout?->title, $masukan->rating, $masukan->category, $masukan->comment];
                    $baris = array_map(fn ($nilai) => preg_match('/^[\s]*[=+@-]/u', (string) $nilai)
                        ? "'".$nilai : $nilai, $baris);
                    fputcsv($output, $baris, ',', '"', '');
                }
                fclose($output);
            }, 'kritik-saran-peserta.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json($query->paginate($filter['per_page'] ?? 20));
    }
}
