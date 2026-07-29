<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $adminId = $admin?->id;

        $packages = [
            [
                'name'           => 'Paket Try Out Basic',
                'description'    => 'Paket pemula untuk latihan soal UTBK. Berisi 5 tiket tryout dan akses selama 1 bulan.',
                'price'          => 99000,
                'discount_price' => null,
                'ticket_amount'  => 5,
                'currency'       => 'IDR',
                'is_active'      => true,
            ],
            [
                'name'           => 'Paket Try Out Premium',
                'description'    => 'Paket unggulan dengan 15 tiket tryout, pembahasan lengkap, dan laporan analitik nilai.',
                'price'          => 299000,
                'discount_price' => 199000,
                'ticket_amount'  => 15,
                'currency'       => 'IDR',
                'is_active'      => true,
            ],
            [
                'name'           => 'Mega Paket UTBK',
                'description'    => 'Paket terlengkap! 30 tiket tryout + live class + konsultasi 1-on-1 dengan tutor berpengalaman.',
                'price'          => 599000,
                'discount_price' => 449000,
                'ticket_amount'  => 30,
                'currency'       => 'IDR',
                'is_active'      => true,
            ],
        ];

        foreach ($packages as $pkg) {
            Package::updateOrCreate(
                ['slug' => Str::slug($pkg['name'])],
                array_merge($pkg, [
                    'slug'       => Str::slug($pkg['name']),
                    'created_by' => $adminId,
                ])
            );
        }
    }
}
