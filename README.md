# StreamNet

Monitor gateway MikroTik. Satu proses pemantau mengambil sampel dari setiap router yang aktif, lalu dashboard menampilkan router yang sedang dipilih.

## Yang dipantau

- Ringkasan jaringan: identitas router, throughput WAN, CPU, RAM, suhu, conntrack, dan DHCP.
- Port chassis per interface, plus kurva trafik 20 menit.
- Perangkat yang terhubung lewat DHCP.
- Firewall dan temuan keamanan.
- Aliran log router.
- Beberapa sesi MikroTik sekaligus. Router lain ditambah di **Pengaturan**, bukan di `.env`.

## Menjalankan

Butuh PHP 8.3, Composer, Node.js, dan MySQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Isi `.env`, lalu:

```bash
php artisan migrate --force
php artisan db:seed
npm install
npm run build
composer dev
```

`composer dev` menjalankan server aplikasi lewat Laravel Octane (FrankenPHP, worker mode, `--watch`), Reverb, dan `php artisan mikrotik:watch`. Pemantau menggilir setiap sesi yang diaktifkan setiap `MIKROTIK_POLL_SECONDS` (bawaan 5 detik) dan menyiarkan hasilnya ke kanal `mikrotik`.

Binary `frankenphp` diunduh ke root proyek oleh `php artisan octane:install --server=frankenphp` dan tidak ikut di-commit. Di produksi:

```bash
php artisan octane:start --host=0.0.0.0 --port=8000
php artisan octane:reload   # setelah deploy
```

FrankenPHP membawa PHP sendiri. `PHP_INI_SCAN_DIR` dikosongkan untuk proses itu (`OCTANE_PHP_INI_SCAN_DIR`) supaya tidak memuat ekstensi `.so` milik PHP sistem.

Satu sampel saja:

```bash
php artisan mikrotik:watch --once
```

## Masuk

Akun operator dibuat saat seed dari:

| Variabel | Fungsi |
| --- | --- |
| `DASHBOARD_EMAIL` | Email login. Bawaan `it@aerisbeaute.com`. |
| `DASHBOARD_PASSWORD` | Password login. Kalau kosong, seeder memakai password bawaannya. |

## Sesi MikroTik

Variabel `MIKROTIK_*` hanya membuat **sesi pertama**, dan hanya kalau belum ada sesi sama sekali serta host dan password terisi.

| Variabel | Fungsi |
| --- | --- |
| `MIKROTIK_HOST` | Alamat router pertama. |
| `MIKROTIK_SCHEME` | `http` atau `https` untuk sesi REST pertama. |
| `MIKROTIK_USERNAME` | User API router. |
| `MIKROTIK_PASSWORD` | Password API router. Disimpan terenkripsi. |
| `MIKROTIK_WAN_INTERFACE` | Interface WAN. Kosongkan supaya dipilih otomatis. |
| `MIKROTIK_TIMEOUT` | Batas waktu permintaan, detik. |
| `MIKROTIK_POLL_SECONDS` | Jeda antar giliran polling. Minimum 2 detik. |
| `MIKROTIK_TORCH` | `true` supaya rate per klien diukur setelah queue (Torch di interface LAN, proses `mikrotik:torch`). |
| `MIKROTIK_TORCH_SECONDS` | Jeda antar pengukuran Torch. Bawaan 5 detik, tiap pengukuran 2 detik. |
| `MIKROTIK_LAN_INTERFACE` | Paksa interface LAN untuk Torch. Kosongkan supaya dicari dari subnet lease. |
| `MIKROTIK_CONNECTION_WARNING` | Ambang conntrack peringatan. |
| `MIKROTIK_CONNECTION_CRITICAL` | Ambang conntrack kritis. |

Daftar sesi ada di `/sesi`. Pengaturan satu router (host, protokol, API UniFi) ada di `/sesi/{nama sesi}`, misalnya `/sesi/AERIS%20%7C%20FTI`. Sesi baru ditambah di `/sesi/baru`. Protokol **API** memakai port 8728 (API-SSL 8729). Itu protokol biner MikroTik, bukan HTTP. REST HTTP/HTTPS hanya dipakai kalau service `www` memang terbuka, biasanya port 80, dan alamatnya berakhiran `/rest`. Port 8728 yang tersimpan sebagai HTTP tetap dibaca sebagai API.

Password tidak dikirim balik ke halaman. Saat mengubah sesi, kosongkan password kalau tidak ingin menggantinya.

Router yang sedang dibuka dipilih dari kartu di `/sesi`, atau dari kartu **Active router** di sidebar. Pilihan itu tersimpan di sesi login. Semua halaman (ringkasan, trafik, DHCP, firewall, log) mengikuti router yang dipilih. Menghapus sesi ikut menghapus sampelnya.

Port API atau REST router harus bisa dijangkau dari mesin yang menjalankan `mikrotik:watch`. User router cukup read-only untuk `system`, `interface`, `ip/dhcp-server`, `ip/firewall`, `log`, dan `user/active`.

## UniFi

Setelah masuk, pilih sesi MikroTik di `/sesi` sebelum membuka konsol. Menu **Access Points** membaca UniFi Network Integration API (`/proxy/network/integration/v1`). API key disimpan terenkripsi di pengaturan sesi itu (`/sesi/{nama}`), jadi sesi router lain tidak memakai console yang sama. Buat API key di UniFi Network → Settings → Control Plane → Integrations.

Kalau belum dihubungkan atau console tidak bisa dijangkau, halaman Access Points menampilkan "Belum terhubung" dengan tautan ke pengaturan.

Data AP, statistik, dan klien di-cache 10 detik (`UNIFI_CACHE_SECONDS`), jadi reload Live tidak membanjiri console.

## Halaman

| Path | Isi |
| --- | --- |
| `/` | Ringkasan jaringan dan matriks port. |
| `/bandwidth` | Interface dan trafik. |
| `/dhcp` | Perangkat terhubung. |
| `/access-points` | Access point dan klien WiFi dari UniFi. |
| `/bandwidth-policy` | Pembagian bandwidth adil (PCQ), batas per klien, burst, dan insiden klien rakus. |
| `/security` | Firewall dan temuan. |
| `/logs` | Aliran log. |
| `/pengaturan` | Tambah, ubah, hapus, dan pilih sesi MikroTik. |

## Uji

```bash
composer test
```

## Deploy

Push ke `main` menjalankan `.github/workflows/deploy.yml`: tes, build image `itaeris/streamnet_app`, lalu di server container lama `streamnet_app` di-stop dan dihapus sebelum container baru jalan di port `2700` pada network `streamnet-network`. MySQL tidak dibuat ulang. `DB_HOST` di secret `BACKEND_ENV` harus nama container MySQL yang sudah ada, dan container itu harus sudah tersambung ke `streamnet-network`.

Saat container mulai, `php artisan migrate --force` hanya menambah migrasi yang belum tercatat. `AdminUserSeeder` membuat user dari `DASHBOARD_EMAIL` dan `DASHBOARD_PASSWORD` sekali. Deploy berikutnya tidak menimpa password.

Nginx di dalam container mendengarkan `2700` dan meneruskan websocket `/app` ke Reverb. Arahkan `https://stream.aeris-fti.com` ke `127.0.0.1:2700`. Di `BACKEND_ENV` produksi, set `APP_URL=https://stream.aeris-fti.com`, `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, `VITE_REVERB_SCHEME=https`, `VITE_REVERB_PORT=443`, dan `VITE_REVERB_APP_KEY` sama dengan `REVERB_APP_KEY`. `VITE_REVERB_*` ikut ter-bake saat image di-build.
