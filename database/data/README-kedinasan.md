# Data referensi jalur CPNS

Kedua berkas di bawah **sudah terisi** dan diseed. Provenansinya dicatat di sini
supaya bisa diperiksa dan disegarkan tahun depan.

Seedernya idempoten (upsert pada kode), jadi mengganti berkas lalu menjalankan
seeder ulang memperbarui baris yang ada - bukan menduplikasinya. Itu penting
karena target peserta menunjuk ke nilai ini.

## kedinasan-prodi.csv

30 sekolah kedinasan. Masuk ke tabel `perguruan_tinggi` (dengan
`jenis = kedinasan`) dan `program_studi`, tabel yang sama dengan PTN, karena
bentuk targetnya identik: sekolah plus program studi.

- Pemisah titik koma, kolom: `KODE_SEKOLAH;NAMA_SEKOLAH;KODE_PRODI;NAMA_PRODI;JENJANG`
- **22 sekolah Kemenhub** dari sumber primer: daftar resmi di
  https://bpsdm.kemenhub.go.id/sekolah-kedinasan, disaring ke lembaga pemberi
  gelar. Unit "Balai" (pusat pelatihan penyegaran) dan empat "Pusat Pengembangan
  Sumber Daya Manusia" (unit administratif) tidak diikutkan karena bukan sekolah
  tujuan pendaftaran.
- **8 sekolah lainnya** - PKN STAN, IPDN, Polstat STIS, STMKG, STIN, Poltek SSN,
  Poltekpin, Poltek Imipas - dari rilis formasi Sekolah Kedinasan 2026
  (Kemenpan-RB/BKN, total 4.770 kursi), dicek silang di dua pemberitaan yang
  saling bebas. Rilis itu juga yang memperbaiki satu kesalahan yang beredar di
  artikel sekunder: Polstat STIS berada di bawah **BPS**, bukan Kemenkeu.
- `KODE_SEKOLAH` adalah kunci internal yang dibuat sendiri (`KD-...`), bukan kode
  resmi BKN - tidak ada kode resmi yang dipublikasikan untuk keperluan ini.
  Fungsinya hanya sebagai kunci upsert supaya penyegaran tidak menduplikasi baris.

### Program studi masih kosong, sengaja

`KODE_PRODI`, `NAMA_PRODI`, dan `JENJANG` dibiarkan kosong. Daftar program studi
per kampus hanya terbit di pengumuman SIPENCATAR masing-masing lembaga, tidak ada
satu sumber yang memuat ketiga puluhnya, dan namanya harus persis karena
tersimpan sebagai target peserta. Menyalinnya dari artikel sekunder - yang sudah
terbukti keliru soal lembaga pengampu - berisiko menghasilkan nama prodi yang
tampak resmi tapi salah.

Sampai kolom itu diisi, kolom "program studi" di form profil tetap bisa diisi
peserta lewat opsi ketikan manual. Untuk mengisinya, tambahkan baris per prodi
dengan `KODE_SEKOLAH` yang sama - satu baris per program studi.

## instansi-formasi.csv

557 instansi untuk pelamar CPNS umum. Masuk ke tabel `instansi` dan `formasi`.

- Pemisah titik koma, kolom: `KODE_INSTANSI;NAMA_INSTANSI;TINGKAT;NAMA_FORMASI;JENJANG`
- **548 instansi daerah** diturunkan mekanis dari daftar wilayah yang sudah
  dipanggang di `FE-UrClass/src/lib/wilayah.ts` (34 provinsi + 514
  kabupaten/kota): setiap daerah punya pemda yang merekrut CPNS, sehingga
  "Pemerintah Provinsi X" dan "Pemerintah Kabupaten/Kota Y" adalah turunan
  langsung, bukan karangan. Ejaannya mengikuti Dapodik, sama seperti picker
  provinsi dan kabupaten di form profil.
- **9 instansi pusat** adalah kementerian/lembaga yang terverifikasi sebagai
  penyelenggara sekolah kedinasan 2026, dan semuanya juga merekrut CPNS.
  Daftar kementerian/lembaga lengkap belum diikutkan.

### Formasi masih kosong, sengaja

`NAMA_FORMASI` dan `JENJANG` dibiarkan kosong. Rincian formasi per instansi
diterbitkan SSCASN per periode seleksi, jumlahnya ribuan, dan portalnya berupa
aplikasi JavaScript tanpa API publik terdokumentasi - endpoint yang dicoba
menjawab 404, dan halaman daftar formasi hanya memuat "Memuat Data...". Tidak ada
berkas yang bisa diunduh dan dipertanggungjawabkan.

Kolom "formasi" di form profil tetap bisa diisi peserta lewat ketikan manual.
Kalau nanti kamu punya rekap formasi (unduhan Excel dari SSCASN atau lampiran
pengumuman instansi), tinggal tambahkan barisnya - satu baris per formasi dengan
`KODE_INSTANSI` yang sama.

## Kalau berkasnya dihapus

Fiturnya tetap jalan. Kedua seeder melewatkan diri dengan peringatan kalau
berkasnya tidak ada, dan setiap picker menerima ketikan manual lewat opsi
`Pakai "<ketikan>"` - persis seperti perlakuan untuk kampus swasta yang tidak
ada di tabel PTN.
