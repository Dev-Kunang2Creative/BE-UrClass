# Aturan kerja BE-UrClass

Setiap aturan di sini lahir dari kejadian nyata di repo ini, dan disertai
sebabnya. Kalau sebabnya tidak lagi berlaku, aturannya boleh dibantah — tapi
bantahlah sebabnya, jangan hanya melanggar aturannya.

---

## 1. Performa: jangan pernah mengueri di dalam loop

Ini sumber masalah performa terbesar yang pernah ditemukan di repo ini. Satu
profil pengukuran menunjukkan `/leaderboard` melakukan **98 kueri** dan
`/result` **113 kueri** untuk satu tryout 90 soal — dan angkanya tumbuh mengikuti
jumlah soal, setiap kali halaman dibuka, oleh setiap peserta.

### Nilai tingkat-tryout dihitung sekali, di luar loop

Tiga hal ini **sifat tryout, bukan sifat peserta** — dua peserta di tryout yang
sama menghasilkan angka yang sama persis:

| Fungsi | Jangan |
|---|---|
| `ScoringService::irtWeights()` | menghitung bobot per soal di dalam loop |
| `ScoringService::maxScoreForTryout()` | memanggil `maxScoreForSession()` per sesi |
| `ScoringService::sessionAggregates()` | menghitung benar/salah/terjawab per sesi |

`maxScoreForSession()` masih ada untuk pemanggil tunggal, tapi ia melakukan tiga
kueri. Memanggilnya seribu kali untuk seribu peserta berarti ribuan kueri yang
seluruhnya menghasilkan **satu angka yang sama**.

### Bobot IRT dihitung satu tempat

`ScoringService::irtWeights()`. Sebelumnya perhitungan identik ada **dua kali** —
di `result()` dan `leaderboard()` — masing-masing dengan satu kueri `COUNT` per
soal. Selain lambat, itu berarti satu sesi bisa bernilai lain di halaman hasil
dan di papan peringkat.

Hasilnya di-cache dengan kunci yang memuat jumlah sesi selesai, jadi **batal
dengan sendirinya** begitu ada peserta baru menyelesaikan tryout. Tidak ada
langkah pembatalan cache yang bisa terlupa. TTL lima menit sebagai jaring
pengaman untuk koreksi jawaban yang tidak menambah sesi.

### Jangan eager-load relasi hanya untuk menghitungnya

**Kejadiannya:** `leaderboard()` memuat `with(['user', 'answers'])` — 60 peserta
× 90 soal = **5.400 model Eloquent dihidrasi**. Profilnya: **15ms SQL, 337ms
PHP**. Bebannya bukan database, tapi hidrasi model.

Setelah diganti agregasi database: **43ms**. Delapan kali lebih cepat.

Kalau yang dibutuhkan hanya angka, minta database menghitungnya. Kalau butuh
baris mentah, pakai `->toBase()->get(['kolom', 'yang', 'perlu'])` — bukan model
lengkap.

### Pakai `join`, bukan `whereHas`, untuk agregasi

`whereHas` menghasilkan subquery berkorelasi yang dievaluasi ulang per baris.
Untuk `COUNT`/`SUM` berkelompok atas tabel besar, `join` jauh lebih murah.

### Ada profiler di repo

`tests/Feature/PerformanceProfileTest.php` — **alat ukur, bukan test kebenaran.**

```bash
php artisan test --filter=PerformanceProfileTest
```

Ia menyeed volume mirip produksi lalu melaporkan jumlah kueri, waktu SQL, dan
waktu PHP per endpoint, plus kueri identik yang berulang (tanda N+1 paling
jelas). **Jalankan ini sebelum dan sesudah mengubah jalur penilaian.**

Jumlah kueri dipilih sebagai metrik utama karena tidak tergantung engine maupun
mesin: kalau ia tumbuh sebanding jumlah baris, itu N+1.

Dua endpoint melaporkan 500 di profiler ini — `/admin/stats` dan
`/admin/sales-report` — karena keduanya memakai `YEAR()` dan `MONTH()` yang tidak
ada di SQLite. **Di MySQL keduanya jalan.** Itu batasan harness, bukan bug; tapi
artinya kedua endpoint itu tidak bisa diuji di test suite.

---

## 2. Migrasi

### Jangan pernah menyunting migrasi yang sudah dijalankan

Tambahkan migrasi maju.

**Kejadiannya:** kolom kedinasan perlu dikembalikan setelah dihapus. Menyunting
migrasi `2026_08_30_180000` ke bentuk awalnya hanya berlaku bagi yang bermigrasi
dari nol, dan memaksa yang lain menjalankan `migrate:fresh` — yang berarti
membuang datanya. Dua migrasi di riwayat lebih murah daripada itu.

Pengecualian yang pernah dipakai: branch belum di-push **dan** kolomnya belum
pernah ada di lingkungan mana pun. Begitu ragu, pakai migrasi maju.

### Migrasi memindahkan data, seeder mengisi bawaan — jangan dua-duanya

**Kejadiannya:** migrasi `proof_requirements` memindahkan akun Instagram jadi
syarat follow, dan `ProofRequirementSeeder` juga menyisipkan tiga syarat bawaan.
Lingkungan lama akan berakhir dengan **lima syarat termasuk yang ganda**.

Sekarang migrasinya hanya memindahkan, dan seedernya melewatkan diri kalau
tabelnya sudah terisi.

### Menghapus kolom pembeda tidak menghapus barisnya

**Kejadiannya:** menghapus `perguruan_tinggi.jenis` meninggalkan 30 baris
kedinasan yang jadi tidak bisa dibedakan dari PTN — peserta UTBK akan menemukan
IPDN di daftar target kampusnya. Barisnya harus dibersihkan terpisah.

---

## 3. Deploy

### Produksi tidak pernah migrasi otomatis

`deploy.yml` tidak menyentuh database sama sekali. Kode baru yang butuh kolom
baru akan **500 sampai `php artisan migrate` dijalankan manual** di server.

Setiap kali sebuah perubahan menambah migrasi, sebut ini saat menyerahkan
pekerjaan. Jangan menganggap deploy sudah cukup.

### Marker seed di dev diam-diam melewatkan seeder yang ditambahkan kemudian

`deploy-dev.yml` menjalankan seeder sekali per lingkungan lewat berkas penanda di
`storage/`. Seeder yang ditambahkan **setelah** penandanya terbuat tidak akan
pernah jalan.

**Kejadiannya:** `SubtestCategorySeeder` milik rekan kerja hampir terlewat begitu
— dan tanpa kategori subtes, CRUD subtes admin rusak. Setiap seeder baru harus
ditambahkan ke loop berpenjaga di `deploy-dev.yml` dengan penandanya sendiri.

---

## 4. Penilaian

### Skema penilaian ditentukan jalur ujiannya, bukan dipilih bebas admin

- **UTBK → selalu IRT.** Otomatis, tidak ditawarkan sebagai pilihan.
- **CPNS → dipilih:** benar/salah (TWK, TIU — 5 poin per jawaban benar) atau
  bobot per opsi (TKP — tiap opsi bernilai 1–5, tanpa nilai 0, wajib ada satu
  opsi bernilai 5).

**IRT tidak punya "nilai jawaban benar" atau "nilai jawaban salah" yang diisi
admin.** IRT menilai benar/salah, lalu bobot tiap soal diturunkan dari hasil
seluruh peserta. Menampilkan kolom poin pada skema IRT adalah salah konsep.
`SubtestController` memaksa `scoring_scheme = irt` dengan skor netral untuk
setiap subtes UTBK — apa pun yang dikirim klien.

Jangan bingungkan dua hal ini:

| Ditentukan di | Menjawab |
|---|---|
| Skema penilaian (subtes) | berapa nilai satu jawaban |
| Saklar `use_irt` (tryout) | bagaimana nilai-nilai itu dijumlahkan jadi skor akhir |

Untuk SKD CPNS yang ambangnya angka mutlak (TWK 65, TIU 80, TKP 166 dari 550),
`use_irt` di tryout-nya seharusnya **dimatikan**.

### Rincian per subtes tidak boleh berbeda dari totalnya

`perSubtestBreakdown()` sengaja memakai kolom `score` yang sama dengan
`rawScoreForSession()` dan `maxScoreForQuestion()` yang sama dengan
`maxScoreForTryout()`. Kalau dua tempat menghitung sendiri, keduanya akan
menyimpang.

---

## 5. Data referensi

### Jangan pernah mengarang daftar resmi

Ini aturan paling keras di berkas ini. Nilai-nilai ini tersimpan sebagai target
peserta dan ikut dirender; nama yang terlihat resmi tapi salah lebih berbahaya
daripada kolom kosong.

- Data referensi di-**commit sebagai CSV** di `database/data/`, bukan diambil saat
  seeding. Seeding tidak boleh bergantung pada host pihak ketiga yang bisa mati.
- Seeder **upsert pada kode resmi**, jadi menyegarkan CSV lalu menjalankan ulang
  memperbarui baris yang ada, bukan menduplikasinya.
- Seeder **melewatkan diri dengan peringatan** kalau berkasnya tidak ada. Fitur
  targetnya tetap jalan karena setiap picker menerima ketikan manual.
- Provenansinya dicatat di `database/data/README-kedinasan.md`. Kalau menambah
  data referensi, catat sumbernya di situ.

Yang pernah tertangkap karena disiplin ini: dua kesalahan sumber sekunder
(Polstat STIS di bawah BPS, bukan Kemenkeu; dan satu politeknik yang salah
kategori) — ditemukan dengan mengecek silang ke rilis resmi.

### Formasi CPNS tidak bisa diambil otomatis

Tidak ada API publik, portalnya aplikasi JavaScript, dan gerbangnya membalas
**403 Access denied** setelah beberapa permintaan. **Jangan probing berulang ke
API pemerintah.**

Jalan masuk yang sah: impor Excel di panel admin
(`AdminFormasiImportController`). Selama belum ada satu formasi pun,
`GET /api/formasi/status` menjawab `is_open: false`, kolomnya tidak diwajibkan,
dan peserta diberi pemberitahuan alih-alih picker kosong.

Statusnya diturunkan dari datanya sendiri, bukan dari saklar. Konsekuensinya
perlu disadari: **mengunggah beberapa baris untuk percobaan langsung mengubah apa
yang dilihat semua peserta.** Panel admin menampilkan keadaan yang sedang
berlaku supaya itu tidak jadi kejutan.

---

## 6. Kebersihan data

### `Str::title()` merusak nama resmi

Pakai pola `properName()` yang ada di seeder: title-case **hanya** kalau
sumbernya huruf besar semua.

**Kejadiannya:** "Politeknik Imigrasi dan Pemasyarakatan" jadi "Politeknik
Imigrasi **Dan** Pemasyarakatan". CSV PTN dari SNPMB seluruhnya kapital sehingga
butuh dirapikan, sementara berkas kedinasan sudah tertulis benar.

### Tanggal kedaluwarsa tanpa jam mati di tengah malam

**Kejadiannya:** kode redeem yang kedaluwarsa "hari ini" ditolak sebagai
`inactive`, karena `expired_at` bertanggal saja tersimpan sebagai `00:00:00`.
Perbaikannya `endOfDay()`, plus migrasi untuk baris yang sudah ada.

Setiap kolom kedaluwarsa yang diisi dari input bertanggal saja harus dinormalkan
ke akhir hari.

### Nilai yang dipamerkan ke peserta harus diwaraskan saat impor

Impor formasi mengganti tahun yang tidak masuk akal (`"2O26"` dengan huruf O)
dengan tahun berjalan, bukan menyimpannya apa adanya. Tag HTML (`<`, `>`) ditolak
per baris dengan nomor barisnya.

---

## 7. Keamanan

### `composer audit` terpisah dari `npm audit`

Dashboard CVE yang dipakai hanya membaca npm. `composer audit` pernah menemukan
**41 advisory di 13 paket** yang tidak muncul di sana sama sekali — termasuk
`guzzlehttp/guzzle` CVE-2026-69246 (host nonkanonik bisa melewati pemeriksaan
berbasis host, relevan karena Midtrans dan Socialite lewat Guzzle) dan tiga
advisory high di `phpoffice/phpspreadsheet`, yaitu jalur impor soal Excel —
satu-satunya tempat aplikasi menerima berkas dari admin.

Jalankan `composer audit` setiap kali menyentuh dependensi. Target: bersih.

Constraint di `composer.json` sengaja longgar (`^12.0`, `^3.3`), jadi versi
tambalan biasanya masuk lewat `composer update` **tanpa mengubah manifest** —
hanya `composer.lock` yang berubah.

### Endpoint admin diuji juga dari sisi peserta

Setiap kelompok endpoint admin baru butuh test yang memastikan peserta menerima
`403`. Pola ini sudah ada di `ProofRequirementTest` dan
`CpnsTargetAndFormasiImportTest`; ikuti.

---

## 7b. Kredensial pihak ketiga yang diatur admin

Pengguna menyatakan ini dengan tegas: **endpoint dan API key tidak boleh
tersebar.** Pola yang dipakai asisten AI (`AiSetting`, `AiChatService`) adalah
acuan untuk integrasi berkunci berikutnya.

Empat aturannya:

1. **Kredensial tidak pernah keluar dari server.** Frontend hanya mengenal
   endpoint milik aplikasi sendiri (`POST /api/chat`), dan seluruh permintaan ke
   pihak ketiga terjadi di server. Kunci yang pernah sampai ke browser harus
   dianggap bocor.
2. **Terenkripsi saat disimpan** (cast `encrypted`) dan `$hidden` di model,
   sehingga dump database tidak memuat kunci yang bisa dipakai dan model yang
   ikut ter-serialize karena kelalaian tidak membocorkannya.
3. **Panel admin hanya menerima bentuk tersamar.** Bahkan admin tidak bisa
   membaca kembali kuncinya. Konsekuensinya, kolom kunci yang dikirim kosong
   berarti "pertahankan yang ada" — kalau tidak, masknya yang tersimpan sebagai
   kunci dan asisten mati dengan 401 yang tidak jelas sebabnya.
4. **Galat pihak ketiga diterjemahkan sebelum diteruskan**, dan payload tidak
   pernah masuk log. Badan galat provider sering memuat kunci dan URL, dan log
   adalah tempat kunci paling sering bocor tanpa disadari.

### URL yang bisa diatur admin dan dipanggil server adalah jalur SSRF

Pakai `App\Support\SafeOutboundUrl` sebelum memanggil URL apa pun yang asalnya
dari input. Ia menolak selain https, loopback, rentang privat, link-local, dan
nama domain internal.

Yang paling berbahaya `169.254.169.254` — endpoint metadata di AWS, GCP, Azure,
dan DigitalOcean, yang sering menyajikan kredensial instans tanpa autentikasi
apa pun. Tanpa penjagaan ini, kolom endpoint di panel admin adalah cara membaca
kredensial server lewat servernya sendiri.

Periksa **dua kali**: saat menyimpan dan tepat sebelum permintaan dikirim. Yang
kedua bukan pengulangan sia-sia — nama host bisa menunjuk alamat berbeda di dua
waktu, sehingga pemeriksaan saat menyimpan saja bisa dilewati.

### Fitur berbiaya per panggilan wajib punya batas per pengguna

Chat AI membebani biaya setiap pesan. Tanpa kuota per akun, satu pengguna — atau
satu skrip yang memakai tokennya — bisa menghabiskan anggaran sendirian dalam
satu malam.

Kuotanya dihitung **setelah** panggilan berhasil: permintaan yang gagal karena
provider bermasalah tidak boleh memakan kuota pengguna. Dan `is_active` bawaannya
`false` — fitur berbiaya tidak boleh hidup hanya karena seeder dijalankan.

---

## 8. Verifikasi sebelum commit

```bash
php artisan test                 # seluruh suite harus lolos
composer audit                   # harus bersih
php artisan route:list --path=…   # rute baru benar-benar terdaftar
```

Untuk perubahan yang menyentuh penilaian, tambahkan:

```bash
php artisan test --filter=PerformanceProfileTest
```

Test yang menjaga hal paling mudah rusak diam-diam, jangan dihapus:

- `utbk scoring remains irt scale 1000`
- `cpns result returns actual score and dynamic max score`
- `daftar kampus terpisah menurut jenis` — menjaga IPDN tidak bocor ke daftar PTN
- `pendaftaran meminta satu bukti per syarat`
- `bukti tetap bernama setelah syaratnya dihapus`

### Jangan me-rollback migrasi rekan kerja

**Kejadiannya:** `migrate:rollback` membatalkan migrasi rekan kerja yang berada
di batch yang sama, dan lima kategori subtes hilang sampai dimigrasi serta
diseed ulang. Periksa `php artisan migrate:status` dan isi batch terakhir sebelum
me-rollback apa pun.

---

## 9. Lingkungan lokal

Database kerja: `be_urclass` (MAMP, socket
`/Applications/MAMP/tmp/mysql/mysql.sock`).

Kalau butuh volume besar untuk mengukur performa, **buat database terpisah**,
jangan mengotori database kerja. Yang pernah dipakai: `urclass_profile`. Hapus
setelah selesai:

```sql
DROP DATABASE urclass_profile;
```
