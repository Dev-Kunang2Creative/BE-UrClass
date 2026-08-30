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
 * Nama dan gambarnya mengikuti aset resmi: Coba Dulu, Gaspol, All Out.
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

        // Slug sengaja tidak ikut berganti nama. Kolom inilah kunci upsert-nya,
        // dan order_items serta user_package_enrollments menunjuk ke baris yang
        // sama - mengganti slug berarti membuat baris baru dan meninggalkan
        // riwayat pembelian menggantung di baris lama.
        $packages = [
            [
                // Dulu "Paket Tryout Basic".
                'slug'           => 'paket-try-out-basic',
                'name'           => 'Paket Coba Dulu',
                'description'    => 'Baru mau nyemplung? 5 tiket buat ngerasain tryout beneran. Satu tiket = sekali ngerjain, bebas dipakai di UTBK atau CPNS.',
                'thumbnail'      => 'packages/thumbnails/paket-coba-dulu.webp',
                'price'          => 99000,
                'discount_price' => null,
                'ticket_amount'  => 5,
            ],
            [
                // Dulu "Paket Tryout Premium".
                'slug'           => 'paket-try-out-premium',
                'name'           => 'Paket Gaspol',
                'description'    => 'Udah niat serius? 15 tiket plus pembahasan lengkap dan analitik nilai, biar tau lemahnya di subtes mana. Hemat 33%, no debat.',
                'thumbnail'      => 'packages/thumbnails/paket-gaspol.webp',
                'price'          => 299000,
                'discount_price' => 199000,
                'ticket_amount'  => 15,
            ],
            [
                // Dulu "Mega Paket UTBK", lalu "Paket Tryout Ultimate".
                'slug'           => 'mega-paket-utbk',
                'name'           => 'Paket All Out',
                'description'    => 'Mode maksimal: 30 tiket, live class, dan konsultasi 1-on-1. Buat kamu yang ngejar target dan ogah setengah-setengah.',
                'thumbnail'      => 'packages/thumbnails/paket-all-out.webp',
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
