<?php

namespace App\Console\Commands;

use App\Models\TicketLog;
use App\Models\UserKelasEnrollment;
use App\Models\UserPackageEnrollment;
use App\Models\TicketRedeemRedemption;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillTicketLogs extends Command
{
    protected $signature = 'tickets:backfill-logs {--dry-run : Preview tanpa menyimpan data}';

    protected $description = 'Backfill ticket_logs dari data historis (enrollments & redemptions)';

    public function handle(): int
    {
        $isDry = $this->option('dry-run');

        if ($isDry) {
            $this->warn('[DRY RUN] Tidak ada data yang akan disimpan.');
        }

        $inserted = 0;
        $skipped  = 0;

        // --- 1. Pembelian Paket ---
        $this->info('Memproses user_package_enrollments...');

        UserPackageEnrollment::with(['package', 'user'])->chunkById(100, function ($enrollments) use ($isDry, &$inserted, &$skipped) {
            foreach ($enrollments as $enrollment) {
                $package = $enrollment->package;
                if (! $package || $package->ticket_amount <= 0) {
                    continue;
                }

                $alreadyExists = TicketLog::where('user_id', $enrollment->user_id)
                    ->where('source', 'paket')
                    ->where('description', $package->name)
                    ->whereDate('created_at', Carbon::parse($enrollment->enrolled_at)->toDateString())
                    ->exists();

                if ($alreadyExists) {
                    $skipped++;
                    continue;
                }

                if (! $isDry) {
                    TicketLog::create([
                        'user_id'     => $enrollment->user_id,
                        'type'        => 'credit',
                        'amount'      => $package->ticket_amount,
                        'source'      => 'paket',
                        'description' => $package->name,
                        'created_at'  => $enrollment->enrolled_at,
                        'updated_at'  => $enrollment->enrolled_at,
                    ]);
                }

                $inserted++;
            }
        });

        // --- 2. Pembelian Kelas ---
        $this->info('Memproses user_kelas_enrollments...');

        UserKelasEnrollment::with(['kelas'])->chunkById(100, function ($enrollments) use ($isDry, &$inserted, &$skipped) {
            foreach ($enrollments as $enrollment) {
                $kelas = $enrollment->kelas;
                if (! $kelas || ($kelas->ticket_amount ?? 0) <= 0) {
                    continue;
                }

                $alreadyExists = TicketLog::where('user_id', $enrollment->user_id)
                    ->where('source', 'kelas')
                    ->where('description', $kelas->name)
                    ->whereDate('created_at', Carbon::parse($enrollment->enrolled_at)->toDateString())
                    ->exists();

                if ($alreadyExists) {
                    $skipped++;
                    continue;
                }

                if (! $isDry) {
                    TicketLog::create([
                        'user_id'     => $enrollment->user_id,
                        'type'        => 'credit',
                        'amount'      => $kelas->ticket_amount,
                        'source'      => 'kelas',
                        'description' => $kelas->name,
                        'created_at'  => $enrollment->enrolled_at,
                        'updated_at'  => $enrollment->enrolled_at,
                    ]);
                }

                $inserted++;
            }
        });

        // --- 3. Redeem Kode ---
        $this->info('Memproses ticket_redeem_redemptions...');

        TicketRedeemRedemption::with(['code'])->chunkById(100, function ($redemptions) use ($isDry, &$inserted, &$skipped) {
            foreach ($redemptions as $redemption) {
                $code = $redemption->code;
                if (! $code) {
                    continue;
                }

                $alreadyExists = TicketLog::where('user_id', $redemption->user_id)
                    ->where('source', 'redeem')
                    ->where('description', $code->code)
                    ->exists();

                if ($alreadyExists) {
                    $skipped++;
                    continue;
                }

                if (! $isDry) {
                    TicketLog::create([
                        'user_id'     => $redemption->user_id,
                        'type'        => 'credit',
                        'amount'      => $redemption->ticket_amount,
                        'source'      => 'redeem',
                        'description' => $code->code,
                        'created_at'  => $redemption->redeemed_at,
                        'updated_at'  => $redemption->redeemed_at,
                    ]);
                }

                $inserted++;
            }
        });

        $this->newLine();
        $action = $isDry ? 'Akan diinsert' : 'Berhasil diinsert';
        $this->info("{$action}: {$inserted} log");
        $this->line("Dilewati (sudah ada): {$skipped}");

        return self::SUCCESS;
    }
}
