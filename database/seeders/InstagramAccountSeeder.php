<?php

namespace Database\Seeders;

use App\Models\InstagramAccount;
use Illuminate\Database\Seeder;

/**
 * Dua akun yang sebelumnya ditulis langsung di halaman detail tryout.
 *
 * Diseed supaya pendaftaran tryout gratis tidak mendadak kehilangan daftar
 * akunnya saat fitur ini dipasang; selanjutnya admin bebas menambah, mengubah,
 * atau menonaktifkannya lewat halaman Bukti Follow.
 */
class InstagramAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['username' => 'fdlyshdq', 'order_no' => 1],
            ['username' => 'basykailakh', 'order_no' => 2],
        ];

        foreach ($accounts as $account) {
            InstagramAccount::updateOrCreate(
                ['username' => $account['username']],
                ['order_no' => $account['order_no'], 'is_active' => true],
            );
        }
    }
}
