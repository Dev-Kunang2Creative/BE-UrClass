<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The intended catalogue: one ladder of ticket bundles, sold to both tracks.
 *
 * There used to be two ladders - three UTBK packages here and two SKD packages
 * in CpnsContentSeeder - even though a ticket is a single balance that works on
 * either track. Names and copy no longer mention a track, because the product
 * does not have one.
 *
 * Rows are keyed by an explicit slug, not by Str::slug($name), so renaming a
 * package updates it in place instead of leaving the old row behind.
 */
class PackageSeeder extends Seeder
{
    /**
     * Slugs of the per-track packages this seeder replaces. Deactivated rather
     * than deleted: order_items and user_package_enrollments point at them, and
     * anyone who bought one keeps their tickets - the balance is not per-package.
     */
    private const RETIRED_SLUGS = [
        'paket-skd-starter',
        'paket-skd-intensif',
    ];

    public function run(): void
    {
        $adminId = User::where('role', 'admin')->first()?->id;

        $packages = [
            [
                // Was "Paket Try Out Basic"; slug kept so the row is updated.
                'slug'           => 'paket-try-out-basic',
                'name'           => 'Paket Tryout Basic',
                'description'    => 'Paket pemula: 5 tiket tryout. Satu tiket bisa dipakai untuk tryout UTBK maupun CPNS.',
                'price'          => 99000,
                'discount_price' => null,
                'ticket_amount'  => 5,
            ],
            [
                'slug'           => 'paket-try-out-premium',
                'name'           => 'Paket Tryout Premium',
                'description'    => '15 tiket tryout dengan pembahasan lengkap dan laporan analitik nilai. Berlaku untuk UTBK dan CPNS.',
                'price'          => 299000,
                'discount_price' => 199000,
                'ticket_amount'  => 15,
            ],
            [
                // Was "Mega Paket UTBK".
                'slug'           => 'mega-paket-utbk',
                'name'           => 'Paket Tryout Ultimate',
                'description'    => 'Paket terlengkap: 30 tiket tryout, live class, dan konsultasi 1-on-1. Bebas dipakai di jalur UTBK atau CPNS.',
                'price'          => 599000,
                'discount_price' => 449000,
                'ticket_amount'  => 30,
            ],
        ];

        foreach ($packages as $pkg) {
            Package::updateOrCreate(
                ['slug' => $pkg['slug']],
                array_merge($pkg, [
                    'currency'   => 'IDR',
                    'is_active'  => true,
                    'created_by' => $adminId,
                ])
            );
        }

        $retired = Package::whereIn('slug', self::RETIRED_SLUGS)
            ->where('is_active', true)
            ->get();

        foreach ($retired as $package) {
            $package->update(['is_active' => false]);
            $this->command?->warn("  package retired (is_active=false): {$package->slug}");
        }
    }
}
