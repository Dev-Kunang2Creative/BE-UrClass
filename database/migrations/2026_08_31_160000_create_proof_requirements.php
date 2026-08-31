<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Syarat bukti pendaftaran tryout gratis, menggantikan daftar akun Instagram.
 *
 * Bentuk sebelumnya menyamakan "akun yang wajib di-follow" dengan "bukti yang
 * wajib diunggah": jumlah bukti diturunkan dari jumlah akun aktif. Itu cukup
 * selama syaratnya memang hanya follow. Begitu syaratnya bermacam - follow, tag
 * teman, bagikan ke story - tabel akun tidak bisa menyatakannya, karena "tag 3
 * temanmu" bukan sebuah akun.
 *
 * Sekarang yang didaftar adalah syaratnya sendiri. Tiap baris satu slot unggahan
 * dengan instruksinya masing-masing, dan tautan akun jadi properti opsional -
 * bukan lagi identitas barisnya.
 *
 * Dua tabel yang sama-sama berarti "bukti apa yang kami minta" pasti menyimpang
 * suatu saat, jadi instagram_accounts dibuang, bukan dibiarkan berdampingan.
 * Isinya dipindahkan lebih dulu supaya pendaftaran tryout gratis tidak mendadak
 * kehilangan syaratnya di lingkungan yang sudah jalan. Migrasi ini hanya
 * memindahkan yang sudah ada dan tidak menyisipkan syarat bawaan - itu urusan
 * ProofRequirementSeeder, yang melewatkan diri kalau tabelnya sudah terisi.
 * Kalau keduanya menyisipkan, lingkungan lama berakhir dengan syarat ganda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_requirements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Judul slot yang dilihat peserta, mis. "Bukti follow Instagram".
            $table->string('title');
            // Instruksi rinci: apa yang harus terlihat di tangkapan layarnya.
            $table->text('instruction')->nullable();
            // Tautan yang perlu dibuka peserta lebih dulu, kalau ada. Untuk
            // syarat follow ini profil akunnya; untuk "bagikan ke story" biasanya
            // tidak ada.
            $table->string('link_url')->nullable();
            $table->string('link_label')->nullable();
            // Ikon dari daftar tertutup, sekadar penanda visual. Bukan sumber
            // kebenaran apa pun - kalau nilainya asing, antarmuka memakai bawaan.
            $table->string('icon', 32)->nullable();
            $table->unsignedSmallInteger('order_no')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'order_no']);
        });

        // Bukti tersimpan per syarat, bukan sebagai tumpukan gambar tanpa nama.
        // Tanpa ini admin yang meninjau tidak bisa tahu tangkapan layar mana yang
        // menjawab syarat mana - dan begitu syaratnya lebih dari satu macam, itu
        // justru pertanyaan pertamanya.
        //
        // Kolom proof_images yang lama dibiarkan: pendaftaran yang sudah masuk
        // memakainya, dan menuliskan ulang riwayat demi keseragaman berarti
        // menebak syarat mana yang berlaku saat itu.
        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->json('proof_details')->nullable()->after('proof_images');
        });

        $this->pindahkanAkunInstagram();

        Schema::dropIfExists('instagram_accounts');
    }

    /**
     * Setiap akun aktif jadi satu syarat follow, lengkap dengan tautan profilnya.
     *
     * Hasilnya identik dengan perilaku sebelumnya: jumlah slot sama dengan jumlah
     * akun, dan tiap slot menunjuk satu akun. Yang berubah hanya sekarang admin
     * bisa mengubah judul dan instruksinya, atau menambah syarat yang bukan
     * follow sama sekali.
     */
    private function pindahkanAkunInstagram(): void
    {
        if (! Schema::hasTable('instagram_accounts')) {
            return;
        }

        $akun = DB::table('instagram_accounts')
            ->orderBy('order_no')
            ->orderBy('username')
            ->get();

        if ($akun->isEmpty()) {
            return;
        }

        $urut = 1;
        foreach ($akun as $item) {
            DB::table('proof_requirements')->insert([
                'id' => (string) Str::ulid(),
                'title' => $item->label ?: 'Bukti follow @'.$item->username,
                'instruction' => 'Follow akun @'.$item->username.
                    ', lalu unggah tangkapan layar yang memperlihatkan tombolnya sudah berubah jadi "Following".',
                'link_url' => 'https://www.instagram.com/'.$item->username.'/',
                'link_label' => '@'.$item->username,
                'icon' => 'instagram',
                'order_no' => $urut++,
                'is_active' => $item->is_active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('username')->unique();
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('order_no')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'order_no']);
        });

        // Hanya syarat yang jelas-jelas menunjuk akun Instagram yang bisa
        // dipulihkan; syarat lain tidak punya padanan di bentuk lama.
        foreach (DB::table('proof_requirements')->where('icon', 'instagram')->get() as $syarat) {
            if (! $syarat->link_url || ! preg_match('#instagram\.com/([^/]+)#', $syarat->link_url, $m)) {
                continue;
            }

            DB::table('instagram_accounts')->insert([
                'id' => (string) Str::ulid(),
                'username' => $m[1],
                'label' => $syarat->title,
                'order_no' => $syarat->order_no,
                'is_active' => $syarat->is_active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->dropColumn('proof_details');
        });

        Schema::dropIfExists('proof_requirements');
    }
};
