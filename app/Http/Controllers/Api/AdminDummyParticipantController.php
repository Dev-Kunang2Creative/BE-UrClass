<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\UserTryoutAccess;
use App\Services\AuditLogger;
use App\Services\DummyParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDummyParticipantController extends Controller
{
    public function __construct(private readonly DummyParticipantService $service) {}

    public function injectRandom(Request $request, Tryout $tryout): JsonResponse
    {
        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:200'],
            'score_preset' => ['required', Rule::in(['normal', 'competitive', 'random'])],
        ]);

        $count = $this->service->injectRandom(
            $tryout,
            (int) $validated['count'],
            $validated['score_preset'],
        );

        AuditLogger::log(
            'TryoutDummyParticipant',
            'inject_random',
            "Admin menginjeksi {$count} peserta dummy ke tryout \"{$tryout->title}\".",
            $request->user(),
            $tryout,
        );

        return response()->json([
            'message' => "Berhasil menginjeksi {$count} peserta dummy",
            'count' => $count,
        ]);
    }

    public function injectExcel(Request $request, Tryout $tryout): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ]);

        $sheet = IOFactory::load($validated['file']->getRealPath())->getActiveSheet();
        $rows = collect($sheet->toArray(null, true, true, true));
        $headers = collect($rows->shift() ?? [])
            ->mapWithKeys(fn ($value, $column) => [strtolower(trim((string) $value)) => $column]);

        $requiredHeaders = [
            'name', 'school_name', 'region_province', 'region_city', 'score_percentage',
        ];
        $missingHeaders = collect($requiredHeaders)->reject(fn ($header) => $headers->has($header));

        if ($missingHeaders->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => ['Kolom wajib tidak ditemukan: '.$missingHeaders->implode(', ').'.'],
            ]);
        }

        $participants = $this->validatedImportRows($rows, $headers, $requiredHeaders);
        $count = $this->service->injectRows($tryout, $participants);

        AuditLogger::log(
            'TryoutDummyParticipant',
            'inject_excel',
            "Admin mengimpor {$count} peserta dummy ke tryout \"{$tryout->title}\".",
            $request->user(),
            $tryout,
        );

        return response()->json([
            'message' => "Berhasil menginjeksi {$count} peserta dummy",
            'count' => $count,
        ]);
    }

    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Peserta Dummy');

        $headers = ['name', 'school_name', 'region_province', 'region_city', 'score_percentage'];
        $examples = [
            ['Alya Putri', 'SMAN 3 Semarang', 'Jawa Tengah', 'Kota Semarang', 82],
            ['Rizky Maulana', 'SMAN 5 Makassar', 'Sulawesi Selatan', 'Kota Makassar', 67],
            ['Naufal Pratama', 'SMAN 2 Balikpapan', 'Kalimantan Timur', 'Kota Balikpapan', 74],
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($examples, null, 'A2');
        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF004AAB']],
        ]);

        foreach (range('A', 'E') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            'template-peserta-dummy.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function clear(Request $request, Tryout $tryout): JsonResponse
    {
        $count = $this->service->clear($tryout);

        AuditLogger::log(
            'TryoutDummyParticipant',
            'clear',
            "Admin menghapus {$count} peserta dummy dari tryout \"{$tryout->title}\".",
            $request->user(),
            $tryout,
        );

        return response()->json([
            'message' => "Berhasil membersihkan {$count} peserta dummy",
            'count' => $count,
        ]);
    }

    public function summary(Tryout $tryout): JsonResponse
    {
        $baseQuery = UserTryoutAccess::query()->where('tryout_id', $tryout->id);
        $dummy = (clone $baseQuery)->whereHas('user', fn ($query) => $query->dummy())->count();
        $real = (clone $baseQuery)->whereHas('user', fn ($query) => $query->real())->count();

        return response()->json([
            'total_participants' => $real + $dummy,
            'real_participants' => $real,
            'dummy_participants' => $dummy,
        ]);
    }

    private function validatedImportRows(Collection $rows, Collection $headers, array $requiredHeaders): Collection
    {
        $participants = $rows
            ->map(function (array $row, int $index) use ($headers, $requiredHeaders) {
                $participant = collect($requiredHeaders)->mapWithKeys(fn ($header) => [
                    $header => $row[$headers->get($header)] ?? null,
                ])->all();
                $participant['_row'] = $index + 2;

                return $participant;
            })
            ->filter(fn (array $row) => collect($requiredHeaders)
                ->contains(fn ($header) => trim((string) ($row[$header] ?? '')) !== ''))
            ->values();

        if ($participants->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => ['Berkas tidak memiliki baris peserta.'],
            ]);
        }

        if ($participants->count() > 200) {
            throw ValidationException::withMessages([
                'file' => ['Maksimal 200 peserta dummy dapat diproses dalam satu impor.'],
            ]);
        }

        $errors = [];

        foreach ($participants as $participant) {
            $validator = Validator::make($participant, [
                'name' => ['required', 'string', 'max:255'],
                'school_name' => ['required', 'string', 'max:255'],
                'region_province' => ['required', 'string', 'max:255'],
                'region_city' => ['required', 'string', 'max:255'],
                'score_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            ]);

            if ($validator->fails()) {
                $errors["file.row_{$participant['_row']}"] = [
                    "Baris {$participant['_row']}: ".$validator->errors()->first(),
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $participants->map(fn (array $participant) => collect($participant)
            ->except('_row')
            ->all());
    }
}
