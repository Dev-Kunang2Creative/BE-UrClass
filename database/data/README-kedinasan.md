# Data referensi jalur CPNS

Dua berkas di bawah ini **belum ada di repo** dan harus kamu sediakan sendiri.
Sengaja tidak saya isi: roster sekolah kedinasan beserta program studinya, dan
terutama daftar formasi CPNS, terbit ulang setiap tahun dari BKN. Mengarangnya
dari ingatan akan menghasilkan data yang tampak resmi tapi salah, dan data itu
masuk ke profil peserta.

Seedernya sudah siap dan bersifat idempoten (upsert pada kode), jadi menaruh
berkas baru lalu menjalankan seeder ulang akan memperbarui baris yang ada -
bukan menduplikasinya. Itu penting karena target peserta menunjuk ke nilai ini.

## kedinasan-prodi.csv

Sekolah kedinasan dan program studinya. Masuk ke tabel `perguruan_tinggi`
(dengan `jenis = kedinasan`) dan `program_studi`, tabel yang sama dengan PTN,
karena bentuk targetnya identik: sekolah plus program studi.

- Pemisah titik koma, kolom: `KODE_SEKOLAH;NAMA_SEKOLAH;KODE_PRODI;NAMA_PRODI;JENJANG`
- Sumber: portal Dikdin BKN (https://dikdin.bkn.go.id) dan situs
  masing-masing sekolah. Tidak ada API publik; daftarnya diumumkan per
  tahun anggaran.
- Skalanya kecil - sekitar delapan kementerian/lembaga dengan puluhan prodi -
  jadi menyalin manual sekali setahun jauh lebih murah daripada scraper yang
  akan rusak begitu portalnya berubah.

## instansi-formasi.csv

Instansi dan formasi untuk pelamar CPNS umum. Masuk ke tabel `instansi` dan
`formasi`.

- Pemisah titik koma, kolom: `KODE_INSTANSI;NAMA_INSTANSI;TINGKAT;NAMA_FORMASI;JENJANG`
- `TINGKAT` berisi `pusat` atau `daerah`.
- Sumber: SSCASN (https://sscasn.bkn.go.id), yang menerbitkan rincian formasi
  tiap instansi per periode seleksi. Juga tanpa API publik terdokumentasi.
- Jumlahnya ribuan baris dan berubah tiap tahun, jadi ini memang pekerjaan
  berkas - bukan pekerjaan form entri satu per satu.

## Kalau berkasnya belum ada

Fiturnya tetap jalan. Setiap picker di form profil menerima ketikan manual
lewat opsi `Pakai "<ketikan>"`, jadi peserta bisa mengisi target mereka sendiri
selama tabel referensinya masih kosong - persis seperti perlakuan untuk kampus
swasta yang tidak ada di tabel PTN.
