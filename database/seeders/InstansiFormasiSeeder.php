<?php

namespace Database\Seeders;

use App\Models\Formasi;
use App\Models\Instansi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Instansi dan formasi CPNS, dari berkas CSV yang di-commit.
 *
 * Jumlahnya ribuan baris dan terbit ulang tiap periode seleksi, jadi ini
 * memang pekerjaan berkas - bukan entri satu per satu lewat form. Upsert pada
 * kode instansi dan nama formasi, sehingga menyegarkan CSV lalu menjalankan
 * ulang seeder memperbarui yang ada.
 *
 * Berkasnya sengaja tidak disertakan; lihat database/data/README-kedinasan.md.
 */
class InstansiFormasiSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/instansi-formasi.csv');

        if (! is_readable($path)) {
            $this->command?->warn("  instansi: {$path} belum ada, dilewati (lihat README-kedinasan.md)");

            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';');

        if (! $header) {
            $this->command?->error('  instansi: CSV kosong');
            fclose($handle);

            return;
        }

        $instansiCount = 0;
        $formasiCount = 0;
        $seen = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            [$kode, $nama, $tingkat, $namaFormasi, $jenjang] = array_pad($row, 5, null);

            if (! $nama) {
                continue;
            }

            $key = trim((string) $kode) ?: Str::slug($nama);

            if (! isset($seen[$key])) {
                $instansi = Instansi::updateOrCreate(
                    ['kode' => trim((string) $kode) ?: null, 'nama' => Str::title(trim($nama))],
                    ['tingkat' => strtolower(trim((string) $tingkat)) === 'daerah' ? 'daerah' : 'pusat'],
                );
                $seen[$key] = $instansi->id;
                $instansiCount++;
            }

            if ($namaFormasi) {
                Formasi::updateOrCreate(
                    ['instansi_id' => $seen[$key], 'nama' => Str::title(trim($namaFormasi))],
                    ['jenjang' => trim((string) $jenjang) ?: null],
                );
                $formasiCount++;
            }
        }

        fclose($handle);
        $this->command?->info("  instansi: {$instansiCount} instansi, {$formasiCount} formasi");
    }
}
