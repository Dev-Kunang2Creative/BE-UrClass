<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formasi;
use App\Models\Instansi;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Impor rekap formasi CPNS dari berkas Excel.
 *
 * Rincian formasi per instansi tidak bisa diambil otomatis: SSCASN tidak
 * menyediakan API publik, portalnya berupa aplikasi JavaScript, dan gerbangnya
 * memblokir permintaan berulang. Yang bisa diandalkan adalah berkas yang dipegang
 * admin sendiri - unduhan Excel dari SSCASN atau lampiran pengumuman instansi.
 * Controller ini jalan masuknya.
 *
 * Mengisi 557 instansi satu per satu lewat form bukan pilihan yang masuk akal:
 * satu periode seleksi bisa memuat ribuan formasi.
 *
 * Kolom: NAMA_INSTANSI | KODE_INSTANSI | NAMA_FORMASI | JENJANG | PERIODE
 */
class AdminFormasiImportController extends Controller
{
    /** Kolom yang dibaca, berurutan dari A. */
    private const COLUMNS = ['NAMA_INSTANSI', 'KODE_INSTANSI', 'NAMA_FORMASI', 'JENJANG', 'PERIODE'];

    /** Batas baris per berkas, supaya satu unggahan tidak menghabiskan memori. */
    private const MAX_ROWS = 20000;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            // Instansi adalah tulang punggung referensi yang sudah dikurasi 557
            // baris. Membuat barisnya otomatis dari nama di berkas berarti satu
            // salah ketik menghasilkan instansi kembar, dan formasinya terpecah
            // ke dua entri. Karena itu bawaannya mati: nama yang tidak dikenali
            // dilaporkan, bukan dibuatkan.
            'create_missing_instansi' => ['nullable', 'boolean'],
        ], [
            'file.mimes' => 'File harus berformat Excel (.xlsx atau .xls).',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ]);

        $createMissing = $request->boolean('create_missing_instansi');

        $result = $this->importFromExcel($request->file('file'), $createMissing);

        if ($result['imported'] > 0 || $result['updated'] > 0) {
            AuditLogger::log(
                'Formasi',
                'bulk_import',
                sprintf(
                    'Impor formasi: %d baru, %d diperbarui, %d dilewati%s',
                    $result['imported'],
                    $result['updated'],
                    $result['skipped'],
                    $result['instansi_created'] > 0 ? ", {$result['instansi_created']} instansi baru" : '',
                ),
                $request->user(),
            );
        }

        $pesan = $result['imported'] === 0 && $result['updated'] === 0
            ? 'Tidak ada formasi yang bisa diimpor. Periksa daftar kesalahan di bawah.'
            : sprintf(
                '%d formasi baru ditambahkan%s.%s',
                $result['imported'],
                $result['updated'] > 0 ? ", {$result['updated']} diperbarui" : '',
                $result['skipped'] > 0 ? " {$result['skipped']} baris dilewati." : '',
            );

        return response()->json([
            'message' => $pesan,
            'data' => $result,
        ], $result['imported'] > 0 || $result['updated'] > 0 ? 201 : 422);
    }

    private function importFromExcel(UploadedFile $file, bool $createMissing): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', 'E') as $cell) {
                $cells[] = trim((string) $cell->getFormattedValue());
            }
            $rows[$row->getRowIndex()] = $cells;

            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        if ($rows === []) {
            return $this->emptyResult(['File Excel kosong.']);
        }

        // Baris pertama dibuang kalau memang header. Berkas tanpa header tetap
        // diterima supaya rekap yang disalin-tempel dari lampiran tidak perlu
        // dirapikan lebih dulu.
        $firstKey = array_key_first($rows);
        $first = $rows[$firstKey];
        $looksLikeHeader = stripos($first[0] ?? '', 'instansi') !== false
            || stripos($first[2] ?? '', 'formasi') !== false;

        if ($looksLikeHeader) {
            unset($rows[$firstKey]);
        }

        $errors = [];
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $instansiCreated = 0;

        // Nama instansi dicocokkan tanpa peduli besar-kecil huruf dan spasi
        // ganda, karena rekap dari sumber berbeda menuliskannya berbeda-beda.
        $byNama = [];
        $byKode = [];
        foreach (Instansi::query()->get(['id', 'kode', 'nama']) as $instansi) {
            $byNama[self::normalize($instansi->nama)] = $instansi->id;
            if ($instansi->kode) {
                $byKode[mb_strtoupper(trim($instansi->kode))] = $instansi->id;
            }
        }

        // Nama formasi yang sudah ada per instansi, supaya baris yang berulang
        // dihitung sebagai pembaruan dan bukan gagal menabrak unique index.
        $existing = [];
        foreach (Formasi::query()->get(['id', 'instansi_id', 'nama']) as $formasi) {
            $existing[$formasi->instansi_id][self::normalize($formasi->nama)] = $formasi->id;
        }

        $tahunIni = (int) now()->year;
        $seen = [];

        DB::transaction(function () use (
            &$rows, &$errors, &$imported, &$updated, &$skipped, &$instansiCreated,
            &$byNama, &$byKode, &$existing, &$seen, $createMissing, $tahunIni
        ) {
            foreach ($rows as $line => $cells) {
                if (array_filter($cells) === []) {
                    continue;
                }

                [$namaInstansi, $kodeInstansi, $namaFormasi, $jenjang, $periode] = array_pad($cells, 5, '');

                if ($namaFormasi === '') {
                    $errors[] = "Baris {$line}: NAMA_FORMASI kosong.";
                    $skipped++;

                    continue;
                }

                if (mb_strlen($namaFormasi) > 255) {
                    $errors[] = "Baris {$line}: nama formasi lebih dari 255 karakter.";
                    $skipped++;

                    continue;
                }

                // Tag HTML ditolak, sama seperti di form profil: nilai ini
                // tersimpan sebagai target peserta dan ikut dirender.
                if (preg_match('/[<>]/', $namaFormasi.$jenjang)) {
                    $errors[] = "Baris {$line}: nama formasi atau jenjang mengandung karakter < atau >.";
                    $skipped++;

                    continue;
                }

                $instansiId = null;

                if ($kodeInstansi !== '') {
                    $instansiId = $byKode[mb_strtoupper($kodeInstansi)] ?? null;
                }

                if ($instansiId === null && $namaInstansi !== '') {
                    $instansiId = $byNama[self::normalize($namaInstansi)] ?? null;
                }

                if ($instansiId === null) {
                    $label = $namaInstansi !== '' ? $namaInstansi : $kodeInstansi;

                    if ($label === '') {
                        $errors[] = "Baris {$line}: kolom instansi kosong.";
                        $skipped++;

                        continue;
                    }

                    if (! $createMissing) {
                        $errors[] = "Baris {$line}: instansi \"{$label}\" tidak dikenali.";
                        $skipped++;

                        continue;
                    }

                    $instansi = Instansi::create([
                        'kode' => null,
                        'nama' => $namaInstansi !== '' ? $namaInstansi : $kodeInstansi,
                        // Pemda selalu diawali "Pemerintah"; sisanya pusat.
                        'tingkat' => str_starts_with(mb_strtolower($namaInstansi), 'pemerintah') ? 'daerah' : 'pusat',
                        'is_active' => true,
                    ]);
                    $instansiId = $instansi->id;
                    $byNama[self::normalize($instansi->nama)] = $instansi->id;
                    $instansiCreated++;
                }

                $key = self::normalize($namaFormasi);

                // Baris kembar di dalam satu berkas dihitung sekali saja, kalau
                // tidak baris kedua akan tampak sebagai "diperbarui" padahal
                // isinya sama.
                if (isset($seen[$instansiId][$key])) {
                    $skipped++;

                    continue;
                }
                $seen[$instansiId][$key] = true;

                $atribut = [
                    'jenjang' => $jenjang !== '' ? mb_substr($jenjang, 0, 64) : null,
                    'periode' => self::parsePeriode($periode, $tahunIni),
                    'is_active' => true,
                ];

                if ($id = $existing[$instansiId][$key] ?? null) {
                    Formasi::whereKey($id)->update($atribut);
                    $updated++;

                    continue;
                }

                $baru = Formasi::create(['instansi_id' => $instansiId, 'nama' => $namaFormasi] + $atribut);
                $existing[$instansiId][$key] = $baru->id;
                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'instansi_created' => $instansiCreated,
            // Daftar kesalahan dipotong supaya berkas yang seluruhnya salah tidak
            // mengirim balik ribuan baris. Jumlah utuhnya tetap dilaporkan.
            'errors' => array_slice($errors, 0, 50),
            'error_total' => count($errors),
        ];
    }

    private function emptyResult(array $errors): array
    {
        return [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'instansi_created' => 0,
            'errors' => $errors,
            'error_total' => count($errors),
        ];
    }

    /** Spasi ganda dan besar-kecil huruf diabaikan saat mencocokkan nama. */
    private static function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    /**
     * Periode diterima sebagai tahun empat angka. Di luar rentang yang wajar
     * dianggap salah tulis dan diganti tahun berjalan, bukan disimpan apa adanya
     * - "2O26" atau nomor urut yang tidak sengaja masuk kolom ini tidak boleh
     * jadi periode yang dipamerkan ke peserta.
     */
    private static function parsePeriode(string $value, int $fallback): int
    {
        $tahun = (int) preg_replace('/\D/', '', $value);

        return $tahun >= 2000 && $tahun <= 2100 ? $tahun : $fallback;
    }

    /** Berkas contoh berisi header, satu baris terisi, dan catatan pengisian. */
    public function template(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template Formasi CPNS');

        $sheet->fromArray(self::COLUMNS, null, 'A1');

        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF004AAB']],
        ]);

        // Kolom kode diredupkan karena opsional - pencocokan utamanya lewat nama.
        $sheet->getStyle('B1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF94A3B8']],
        ]);

        $sheet->fromArray([
            ['Pemerintah Kota Surabaya', '', 'Ahli Pertama - Perencana', 'S-1', now()->year],
            ['Kementerian Keuangan', '', 'Ahli Pertama - Analis Anggaran', 'S-1', now()->year],
            ['Pemerintah Kabupaten Aceh Barat', '', 'Terampil - Perawat', 'D-III', now()->year],
        ], null, 'A2');

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Petunjuk ditaruh di sheet sendiri, bukan di bawah data.
        //
        // Kalau catatannya berada di kolom A sheet yang sama, admin yang mengisi
        // template lalu mengunggahnya kembali akan mendapat satu baris kesalahan
        // untuk setiap baris catatan - karena bagi pengimpor, "Cara mengisi:"
        // adalah nama instansi tanpa formasi. Memisahkannya membuat template yang
        // terisi bisa langsung diunggah tanpa dirapikan lebih dulu.
        $petunjuk = $spreadsheet->createSheet();
        $petunjuk->setTitle('Petunjuk');

        $notes = [
            ['Cara mengisi'],
            [''],
            ['NAMA_INSTANSI', 'Wajib. Harus sama dengan nama instansi di halaman Instansi & Formasi.'],
            ['', 'Besar-kecil huruf dan spasi ganda diabaikan, jadi tidak perlu persis sama hurufnya.'],
            ['KODE_INSTANSI', 'Opsional. Isi hanya kalau kamu tahu kode internalnya, mis. PEMKOT-SBY.'],
            ['NAMA_FORMASI', 'Wajib. Tulis persis seperti pengumuman instansinya, maksimal 255 karakter.'],
            ['JENJANG', 'Opsional, mis. S-1, D-III, SMA/SMK.'],
            ['PERIODE', 'Opsional, tahun seleksi empat angka. Kalau kosong dipakai tahun berjalan.'],
            [''],
            ['Yang perlu diketahui'],
            [''],
            ['Bisa diulang', 'Formasi dengan nama sama di instansi yang sama diperbarui, bukan diduplikasi,'],
            ['', 'jadi berkas yang sudah dikoreksi aman diunggah lagi.'],
            ['Ganti nama', 'Formasi yang namanya diubah masuk sebagai baris baru; yang lama tetap ada'],
            ['', 'dan perlu dihapus manual dari halaman Instansi & Formasi.'],
            ['Instansi asing', 'Nama instansi yang tidak dikenali dilaporkan per baris dan tidak diimpor,'],
            ['', 'kecuali kamu mencentang "buat instansi yang belum ada" saat mengunggah.'],
            ['Batas', 'Maksimal '.number_format(self::MAX_ROWS, 0, ',', '.').' baris dan 10 MB per berkas.'],
            ['Efek ke peserta', 'Begitu ada satu formasi terisi, peserta CPNS berhenti melihat pemberitahuan'],
            ['', '"formasi belum dibuka" dan mulai melihat pilihan formasi.'],
            [''],
            ['Baris pertama sheet "Template Formasi CPNS" adalah header. Data mulai dari baris 2.'],
            ['Tiga baris contoh di sana boleh ditimpa atau dihapus.'],
        ];

        $petunjuk->fromArray($notes, null, 'A1');
        $petunjuk->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $petunjuk->getStyle('A10')->getFont()->setBold(true)->setSize(13);
        $petunjuk->getStyle('A3:A19')->getFont()->setBold(true);
        $petunjuk->getColumnDimension('A')->setWidth(18);
        $petunjuk->getColumnDimension('B')->setWidth(95);

        // Sheet data yang aktif saat berkasnya dibuka, dan yang dibaca pengimpor.
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);

        // Pola nama berkas sama dengan berkas lain yang dihasilkan aplikasi ini:
        // <jenis>-<rincian>-<tanggal>.xlsx
        $filename = sprintf('template-formasi-cpns-%s.xlsx', now()->format('Y-m-d'));

        return response()->stream(
            fn () => $writer->save('php://output'),
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ],
        );
    }
}
