<?php

namespace Database\Seeders;

use App\Models\ProofRequirement;
use Illuminate\Database\Seeder;

/**
 * Tiga syarat bawaan, sesuai yang diminta: follow, tag teman, dan bagikan.
 *
 * Diseed supaya lingkungan baru tidak membuka tryout gratis tanpa syarat apa
 * pun. Selanjutnya admin bebas mengubah judul, instruksi, tautan, urutan, atau
 * menambah syarat lain lewat halaman Syarat Bukti - tidak ada yang terpatok di
 * kode.
 *
 * Melewatkan diri kalau sudah ada syarat apa pun. Lingkungan yang sebelumnya
 * memakai daftar akun Instagram sudah mendapat syarat follow-nya dari migrasi,
 * dan menambah tiga bawaan di atasnya berarti syarat ganda plus konfigurasi
 * admin yang tertimpa.
 */
class ProofRequirementSeeder extends Seeder
{
    public function run(): void
    {
        if (ProofRequirement::query()->exists()) {
            $this->command?->warn('  syarat bukti: sudah ada, dilewati');

            return;
        }

        $syarat = [
            [
                'title' => 'Bukti follow Instagram',
                'instruction' => 'Follow akun Instagram kami, lalu unggah tangkapan layar yang memperlihatkan tombolnya sudah berubah jadi "Following".',
                'icon' => 'instagram',
                'order_no' => 1,
            ],
            [
                'title' => 'Bukti tag teman',
                'instruction' => 'Tag minimal 3 temanmu di kolom komentar postingan tryout ini, lalu unggah tangkapan layar komentarmu.',
                'icon' => 'users',
                'order_no' => 2,
            ],
            [
                'title' => 'Bukti bagikan ke story atau grup',
                'instruction' => 'Bagikan poster tryout ini ke Instagram story atau grup WhatsApp-mu, lalu unggah tangkapan layarnya.',
                'icon' => 'share',
                'order_no' => 3,
            ],
        ];

        foreach ($syarat as $item) {
            ProofRequirement::create($item + ['is_active' => true]);
        }

        $this->command?->info('  syarat bukti: '.count($syarat).' syarat');
    }
}
