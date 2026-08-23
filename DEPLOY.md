# Deploy: prod-api.urclass.id

CI/CD via GitHub Actions. Push ke `main` -> otomatis deploy.

> **Repo ini public.** Jangan pernah menaruh IP server, username hosting,
> password, atau isi `.env` di file mana pun di repo ini. Semuanya lewat
> GitHub Secrets. Placeholder `<SSH_USER>` dsb. di bawah sengaja tidak diisi.

## Dua workflow

| Workflow | Kapan jalan | Menyentuh DB? |
|---|---|---|
| [`bootstrap.yml`](.github/workflows/bootstrap.yml) | manual, sekali saja | ya, `migrate` (opsional via checkbox) |
| [`deploy.yml`](.github/workflows/deploy.yml) | tiap push ke `main` | **tidak pernah** |

## Arsitektur

```
push ke main
   |
   v
GitHub Actions runner (ubuntu)
   composer install --no-dev      <- vendor/ dibangun DI SINI, bukan di server
   npm ci && npm run build        <- public/build (route "/" butuh manifest vite)
   |
   | rsync --delete over SSH (key-based)
   v
Hostinger shared hosting, docroot = public_html
   |
   +-- .env         <- milik server, CI TIDAK PERNAH menyentuhnya
   +-- storage/     <- milik server, CI TIDAK PERNAH menyentuhnya
   +-- .htaccess    <- rewrite ke public/ + deny dotfile & direktori app
   |
   v
   artisan optimize:clear -> config/route/view/event:cache
```

`vendor/` dibangun di runner karena Composer di shared hosting sering kena
limit memori. Konsekuensinya server tidak perlu Composer sama sekali.

`.env` dan `storage/` diproteksi dari `rsync --delete` lewat
[`.deployignore`](.deployignore) — file yang di-exclude tidak ikut terhapus.

---

## Setup satu kali

### 1. Pasang deploy key di server

Ini **satu-satunya** langkah yang butuh SSH manual. Setelah ini semua
otomatis.

```bash
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

mkdir -p ~/.ssh && chmod 700 ~/.ssh
echo '<CI_PUBLIC_KEY>' >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Sekalian ambil host key untuk dipin (opsional tapi disarankan):

```bash
ssh-keyscan -p <SSH_PORT> <SSH_HOST> 2>/dev/null
```

### 2. Tambah GitHub Secrets

`Settings -> Secrets and variables -> Actions -> New repository secret`

| Secret | Isi |
|---|---|
| `SSH_HOST` | IP server hosting |
| `SSH_PORT` | port SSH Hostinger |
| `SSH_USER` | username hosting (`uXXXXXXXXX`) |
| `SSH_PRIVATE_KEY` | private key CI, lengkap dengan baris `BEGIN`/`END` |
| `PROD_ENV` | **seluruh isi** file `.env` produksi |
| `SSH_KNOWN_HOSTS` | output `ssh-keyscan` dari langkah 1 (opsional) |

`DEPLOY_PATH` tidak perlu di-set — diturunkan dari `SSH_USER` di dalam
workflow supaya username tidak tertulis di repo public ini.

`PROD_ENV` hanya dipakai `bootstrap.yml`, dan hanya kalau `.env` di server
belum ada. Deploy berikutnya tidak pernah menyentuh `.env`.

### 3. Jalankan bootstrap

`Actions -> Bootstrap + first deploy (one-time) -> Run workflow`

Biarkan checkbox **Run migrations** tercentang untuk run pertama. Tanpa
migrasi API akan 500, karena `SESSION_DRIVER`, `CACHE_STORE`, dan
`QUEUE_CONNECTION` semuanya `database` sehingga tabel `sessions`, `cache`,
dan `jobs` harus ada.

Workflow ini menutup dengan verifikasi otomatis:

| Cek | Harapan |
|---|---|
| `GET /up` | 200 — PHP + boot Laravel + rantai rewrite |
| `GET /` | 200 — session DB + asset vite |
| `GET /api/auth/me` | 401 — routing API + guard Sanctum hidup |
| `GET /.env` | 403 — secret tidak terbaca dari web |
| `GET /vendor/autoload.php` | 403 — direktori app tidak terbaca dari web |

`/up` sengaja tidak dijadikan satu-satunya cek: di Laravel 12 route itu
didaftarkan **tanpa middleware**, jadi 200 di `/up` tidak membuktikan
database jalan. `GET /` yang membuktikannya.

---

## Sesudah bootstrap

Push ke `main` -> `deploy.yml` jalan sendiri. Yang dilakukan tiap deploy:
build, rsync, rebuild cache, health check.

### Migrasi

`deploy.yml` **tidak** menjalankan `migrate` (pilihan desain). Tiap ada
migration baru, jalankan manual:

```bash
cd <DEPLOY_PATH> && php artisan migrate --force
```

Kalau kode baru butuh kolom yang belum ada, deploy tetap sukses tapi
endpoint-nya error — jadi jangan lupa langkah ini.

### Cron (scheduler + queue)

Shared hosting tidak bisa daemon, jadi queue worker dijalankan per menit.
Tambahkan lewat hPanel -> Advanced -> Cron Jobs:

```
* * * * * cd <DEPLOY_PATH> && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd <DEPLOY_PATH> && php artisan queue:work --stop-when-empty --tries=3 --timeout=60 >> /dev/null 2>&1
```

Jalankan setelah migrasi, karena `queue:work` butuh tabel `jobs`.

### Rollback

Deploy ini in-place tanpa direktori rilis, jadi rollback = jalankan
`deploy.yml` dari commit/tag lama via `Run workflow`. Untuk rollback instan
(swap symlink) struktur direktori harus diubah ke layout atomic-release.

---

## Yang masih perlu diisi di `.env` server

Placeholder ini dikirim kosong oleh bootstrap dan harus diisi manual:

- `CLOUDFLARE_TURNSTILE_SITE_KEY` / `CLOUDFLARE_TURNSTILE_SECRET_KEY` —
  **penting**: kalau kosong, `config/services.php` jatuh ke test-key
  Cloudflare (`1x0000...`) yang **selalu lolos**, jadi captcha di endpoint
  auth efektif mati.
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — redirect URI produksinya
  `https://prod-api.urclass.id/api/auth/google/callback`, daftarkan di
  Google Cloud Console.
- `MIDTRANS_SERVER_KEY` / `MIDTRANS_CLIENT_KEY`, lalu set
  `MIDTRANS_IS_PRODUCTION=true`.
- `MAIL_*` — masih `log`, artinya email tidak terkirim, hanya masuk log.

Sesudah mengubah `.env`, cache config harus dibangun ulang:

```bash
cd <DEPLOY_PATH> && php artisan config:cache
```
