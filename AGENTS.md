# Aturan repo ini

Baca `rules.md` sebelum menyentuh kode di repo ini. Isinya aturan yang lahir dari
kejadian nyata di repo ini — N+1 yang membuat papan peringkat delapan kali lebih
lambat, migrasi yang disunting setelah dijalankan, seeder yang diam-diam
terlewat saat deploy — beserta sebab masing-masing.

Yang paling sering menggigit, kalau hanya sempat membaca satu bagian:

1. **Jangan pernah mengueri di dalam loop.** Nilai tingkat-tryout dihitung sekali
   di luar loop lewat `ScoringService::irtWeights()`,
   `maxScoreForTryout()`, dan `sessionAggregates()`.
2. **Jangan pernah menyunting migrasi yang sudah dijalankan.** Tambahkan migrasi
   maju.
3. **Produksi tidak migrasi otomatis.** Perubahan yang menambah migrasi butuh
   `php artisan migrate` manual di server — sebutkan saat menyerahkan pekerjaan.
4. **Jangan pernah mengarang daftar resmi** (formasi CPNS, sekolah kedinasan,
   PTN). Data referensi di-commit sebagai CSV dengan provenansinya dicatat.
5. **`composer audit` terpisah dari `npm audit`** dan pernah menyembunyikan 41
   advisory.

Sebelum commit: `php artisan test` dan `composer audit` harus bersih.
