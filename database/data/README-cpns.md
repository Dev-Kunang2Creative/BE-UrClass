# Data referensi jalur CPNS

`instansi-formasi.csv` **sudah terisi** dan diseed. Provenansinya dicatat di sini
supaya bisa diperiksa dan disegarkan tahun depan.

Seedernya idempoten (upsert pada kode instansi dan nama formasi), jadi mengganti
berkas lalu menjalankan seeder ulang memperbarui baris yang ada - bukan
menduplikasinya. Itu penting karena target peserta menunjuk ke nilai ini.

## instansi-formasi.csv

557 instansi tujuan pelamar CPNS. Masuk ke tabel `instansi` dan `formasi`.

- Pemisah titik koma, kolom: `KODE_INSTANSI;NAMA_INSTANSI;TINGKAT;NAMA_FORMASI;JENJANG`
- `TINGKAT` berisi `pusat` atau `daerah`.
- **548 instansi daerah** diturunkan mekanis dari daftar wilayah yang sudah
  dipanggang di `FE-UrClass/src/lib/wilayah.ts` (34 provinsi + 514
  kabupaten/kota): setiap daerah punya pemda yang merekrut CPNS, sehingga
  "Pemerintah Provinsi X" dan "Pemerintah Kabupaten/Kota Y" adalah turunan
  langsung, bukan karangan. Ejaannya mengikuti Dapodik, sama seperti picker
  provinsi dan kabupaten di form profil.
- **9 instansi pusat** adalah kementerian dan lembaga yang terverifikasi lewat
  rilis formasi 2026 (Kemenkeu, Kemendagri, Kemenhub, Kementerian Hukum,
  Kementerian Imigrasi dan Pemasyarakatan, BPS, BMKG, BIN, BSSN). Daftar
  kementerian/lembaga lengkap belum diikutkan.
- `KODE_INSTANSI` adalah kunci internal yang dibuat sendiri, bukan kode resmi
  BKN. Fungsinya hanya sebagai kunci upsert supaya penyegaran tidak
  menduplikasi baris.

### Formasi masih kosong, sengaja

`NAMA_FORMASI` dan `JENJANG` dibiarkan kosong. Rincian formasi per instansi
diterbitkan SSCASN per periode seleksi, jumlahnya ribuan, dan portalnya berupa
aplikasi JavaScript tanpa API publik terdokumentasi - endpoint yang dicoba
menjawab 404, dan halaman daftar formasi hanya memuat "Memuat Data...". Tidak ada
berkas yang bisa diunduh dan dipertanggungjawabkan, dan menyalin dari artikel
sekunder berisiko menghasilkan nama jabatan yang tampak resmi tapi salah - nama
yang lalu tersimpan sebagai target peserta.

Kolom "formasi" di form profil tetap bisa diisi peserta lewat ketikan manual.
Kalau nanti kamu punya rekap formasi (unduhan Excel dari SSCASN atau lampiran
pengumuman instansi), tinggal tambahkan barisnya - satu baris per formasi dengan
`KODE_INSTANSI` yang sama.

## Kalau berkasnya dihapus

Fiturnya tetap jalan. Seeder melewatkan diri dengan peringatan kalau berkasnya
tidak ada, dan picker instansi maupun formasi menerima ketikan manual lewat opsi
`Pakai "<ketikan>"` - persis seperti perlakuan untuk kampus swasta yang tidak ada
di tabel PTN.
