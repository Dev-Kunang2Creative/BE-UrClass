<?php

namespace Database\Seeders;

use App\Models\PerguruanTinggi;
use App\Models\ProgramStudi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PerguruanTinggiSeeder extends Seeder
{
    /**
     * SNBT 2026 quotas and 2025 applicant counts, from
     * database/data/ptn-prodi-2026.csv (see the README beside it for
     * provenance).
     *
     * Idempotent: upserts keyed on the official kode_ptn / kode_prodi, so
     * running it again after refreshing the CSV updates the figures in place
     * rather than duplicating rows. That matters because a student's saved
     * target points at these ids.
     */
    public function run(): void
    {
        $path = database_path('data/ptn-prodi-2026.csv');

        if (! is_readable($path)) {
            $this->command->error("missing reference data: {$path}");

            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';');

        if ($header === false) {
            fclose($handle);
            $this->command->error('reference data is empty');

            return;
        }

        $col = array_flip(array_map('trim', $header));
        foreach (['KODE_PTN', 'NAMA_PTN', 'KODE_PRODI', 'NAMA_PRODI', 'JENJANG'] as $required) {
            if (! isset($col[$required])) {
                fclose($handle);
                $this->command->error("reference data has no {$required} column");

                return;
            }
        }

        $now = now();
        $universities = [];
        $rows = [];
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < count($header)) {
                $skipped++;

                continue;
            }

            $kodePt = trim($row[$col['KODE_PTN']]);
            $kodeProdi = trim($row[$col['KODE_PRODI']]);

            if ($kodePt === '' || $kodeProdi === '') {
                $skipped++;

                continue;
            }

            $universities[$kodePt] ??= $this->titleCase(trim($row[$col['NAMA_PTN']]));

            $rows[] = [
                'kode_ptn' => $kodePt,
                'kode_prodi' => $kodeProdi,
                'nama' => $this->titleCase(trim($row[$col['NAMA_PRODI']])),
                'jenjang' => trim($row[$col['JENJANG']]),
                'daya_tampung' => $this->intOrNull($row, $col, 'DAYA_TAMPUNG_2026'),
                'peminat' => $this->intOrNull($row, $col, 'PEMINAT_2025'),
                'jenis_portofolio' => $this->portfolioOrNull($row, $col),
            ];
        }
        fclose($handle);

        PerguruanTinggi::upsert(
            collect($universities)->map(fn ($nama, $kode) => [
                'id' => (string) Str::ulid(),
                'kode_ptn' => $kode,
                'nama' => $nama,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['kode_ptn'],
            ['nama', 'updated_at'],
        );

        // id and created_at stay out of the update list, so an existing row
        // keeps its identity across reseeds.
        $ptId = PerguruanTinggi::pluck('id', 'kode_ptn');

        foreach (array_chunk($rows, 500) as $chunk) {
            ProgramStudi::upsert(
                array_map(fn ($r) => [
                    'id' => (string) Str::ulid(),
                    'perguruan_tinggi_id' => $ptId[$r['kode_ptn']],
                    'kode_prodi' => $r['kode_prodi'],
                    'nama' => $r['nama'],
                    'jenjang' => $r['jenjang'],
                    'daya_tampung' => $r['daya_tampung'],
                    'peminat' => $r['peminat'],
                    'jenis_portofolio' => $r['jenis_portofolio'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['kode_prodi'],
                ['perguruan_tinggi_id', 'nama', 'jenjang', 'daya_tampung',
                    'peminat', 'jenis_portofolio', 'updated_at'],
            );
        }

        $this->command->info(sprintf(
            'perguruan tinggi: %d, program studi: %d%s',
            count($universities),
            count($rows),
            $skipped ? ", {$skipped} row(s) skipped as malformed" : '',
        ));
    }

    private function intOrNull(array $row, array $col, string $key): ?int
    {
        if (! isset($col[$key])) {
            return null;
        }

        $raw = trim($row[$col[$key]]);

        // A blank means "not published", which must not collapse into 0 - the
        // difference decides whether keketatan can be computed at all.
        return is_numeric($raw) ? (int) $raw : null;
    }

    private function portfolioOrNull(array $row, array $col): ?string
    {
        if (! isset($col['JENIS_PORTOFOLIO'])) {
            return null;
        }

        $raw = trim($row[$col['JENIS_PORTOFOLIO']]);

        return ($raw === '' || strcasecmp($raw, 'Tidak Ada') === 0) ? null : $raw;
    }

    /**
     * The source ships everything in caps. Store it readable so the frontend
     * does not have to re-case it in every dropdown.
     */
    private function titleCase(string $value): string
    {
        return Str::title(Str::lower($value));
    }
}
