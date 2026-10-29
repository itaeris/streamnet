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

`composer dev` menjalankan server aplikasi, Reverb, dan `php artisan mikrotik:watch`. Pemantau menggilir setiap sesi yang diaktifkan setiap `MIKROTIK_POLL_SECONDS` (bawaan 5 detik) dan menyiarkan hasilnya ke kanal `mikrotik`.

Satu sampel saja:

```bash
php artisan mikrotik:watch --once
```

## Masuk

Akun operator dibuat saat seed dari:

| Variabel | Fungsi |
| --- | --- |
| `DASHBOARD_EMAIL` | Email login. Bawaan `wiki@localhost`. |
| `DASHBOARD_PASSWORD` | Password login. Bawaan `change-me` kalau kosong. |

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
| `MIKROTIK_CONNECTION_WARNING` | Ambang conntrack peringatan. |
| `MIKROTIK_CONNECTION_CRITICAL` | Ambang conntrack kritis. |

Router berikutnya ditambah di `/pengaturan`. Protokol **API** memakai port 8728 (API-SSL 8729). Itu protokol biner MikroTik, bukan HTTP. REST HTTP/HTTPS hanya dipakai kalau service `www` memang terbuka, biasanya port 80, dan alamatnya berakhiran `/rest`. Port 8728 yang tersimpan sebagai HTTP tetap dibaca sebagai API.

Password tidak dikirim balik ke halaman. Saat mengubah sesi, kosongkan password kalau tidak ingin menggantinya.

Router yang sedang dibuka dipilih dari kartu **Active router** di sidebar, atau tombol **Pantau** di Pengaturan. Pilihan itu tersimpan di sesi login. Semua halaman (ringkasan, trafik, DHCP, firewall, log) mengikuti router yang dipilih. Menghapus sesi ikut menghapus sampelnya.

Port API atau REST router harus bisa dijangkau dari mesin yang menjalankan `mikrotik:watch`. User router cukup read-only untuk `system`, `interface`, `ip/dhcp-server`, `ip/firewall`, `log`, dan `user/active`.

## Halaman

| Path | Isi |
| --- | --- |
| `/` | Ringkasan jaringan dan matriks port. |
| `/bandwidth` | Interface dan trafik. |
| `/dhcp` | Perangkat terhubung. |
| `/security` | Firewall dan temuan. |
| `/logs` | Aliran log. |
| `/pengaturan` | Tambah, ubah, hapus, dan pilih sesi MikroTik. |

## Uji

```bash
composer test
```
