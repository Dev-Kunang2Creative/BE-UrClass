<?php

namespace Database\Seeders;

use App\Models\PerguruanTinggi;
use App\Models\ProgramStudi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sekolah kedinasan dan program studinya, dari berkas CSV yang di-commit.
 *
 * Mengikuti pola PerguruanTinggiSeeder: berkasnya ada di repo, bukan diambil
 * saat seeding, supaya seeding tidak bergantung pada host pihak ketiga yang
 * bisa mati. Upsert pada kode resmi, jadi menjalankan ulang setelah menyegarkan
 * CSV memperbarui baris yang ada - penting karena target peserta menunjuk ke
 * nilai ini.
 *
 * Berkasnya sengaja tidak disertakan; lihat database/data/README-kedinasan.md.
 * Tanpa berkas, seeder ini melewatkan diri tanpa menggagalkan seeding lain -
 * fitur targetnya tetap jalan karena picker-nya menerima ketikan manual.
 */
class KedinasanSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/kedinasan-prodi.csv');

        if (! is_readable($path)) {
            $this->command?->warn("  kedinasan: {$path} belum ada, dilewati (lihat README-kedinasan.md)");

            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';');

        if (! $header) {
            $this->command?->error('  kedinasan: CSV kosong');
            fclose($handle);

            return;
        }

        $sekolah = 0;
        $prodi = 0;
        $seen = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            [$kodeSekolah, $namaSekolah, $kodeProdi, $namaProdi, $jenjang] = array_pad($row, 5, null);

            if (! $kodeSekolah || ! $namaSekolah) {
                continue;
            }

            if (! isset($seen[$kodeSekolah])) {
                $school = PerguruanTinggi::updateOrCreate(
                    ['kode_ptn' => trim($kodeSekolah)],
                    ['nama' => Str::title(trim($namaSekolah)), 'jenis' => 'kedinasan'],
                );
                $seen[$kodeSekolah] = $school->id;
                $sekolah++;
            }

            if ($kodeProdi && $namaProdi) {
                ProgramStudi::updateOrCreate(
                    ['kode_prodi' => trim($kodeProdi)],
                    [
                        'perguruan_tinggi_id' => $seen[$kodeSekolah],
                        'nama' => Str::title(trim($namaProdi)),
                        'jenjang' => trim((string) $jenjang) ?: 'Diploma',
                    ],
                );
                $prodi++;
            }
        }

        fclose($handle);
        $this->command?->info("  kedinasan: {$sekolah} sekolah, {$prodi} program studi");
    }
}
