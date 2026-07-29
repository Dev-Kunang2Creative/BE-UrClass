<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $users = User::latest()
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
            ->paginate($perPage);

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user]);
    }

    public function export(Request $request): StreamedResponse
    {
        $users = User::where('role', 'user')
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