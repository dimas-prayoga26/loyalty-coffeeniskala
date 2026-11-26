# Niskala Loyalty Stamp System

Aplikasi Laravel 13 (PHP 8.3+) untuk loyalty stamp pelanggan. Aplikasi ini **tidak punya database sendiri**: semua tabelnya ada di database MySQL milik POS (`pos_live_raw`), dengan prefix `loyalty_`.

## Aturan database (wajib dibaca)

| Boleh | Tidak boleh |
|---|---|
| Membuat/mengubah tabel `loyalty_*` lewat migration | Mengubah, menghapus, atau menulis ke tabel POS (`orders`, `users`, dst.) |
| Membaca tabel POS lewat `App\Models\Pos\PosOrder` / `PosUser` (read-only) | `PosOrder::query()->update()/delete()`: query builder melewati proteksi model |
| `migrate`, `migrate:rollback` (hanya menghapus tabel `loyalty_*`) | `migrate:fresh`, `db:wipe`: menghapus **semua** tabel, termasuk POS (diblokir di `AppServiceProvider`) |
| Test dengan trait `DatabaseTransactions` | Test dengan `RefreshDatabase` / `DatabaseMigrations` (memanggil `migrate:fresh`) |

Hal lain:

- Tabel migrasi Laravel bernama `loyalty_migrations`.
- Session dan cache memakai file, queue memakai sync. Karena itu tidak ada tabel tambahan di database POS.
- `orders.id` dan `users.id` di POS bertipe `INT UNSIGNED`. Kolom FK ke tabel tersebut harus memakai `unsignedInteger()`, bukan `foreignId()`.
- POS menghapus order secara permanen. Karena itu FK ke `orders` dan `users` memakai `ON DELETE SET NULL`. Jejak order tetap tersimpan di `loyalty_stamps.order_code`.

## Setup

```sh
composer install
cp .env.example .env      # DB_* sudah mengarah ke pos_live_raw
php artisan key:generate
php artisan migrate       # hanya membuat tabel loyalty_*
php artisan test          # memakai pos_live_raw, semua perubahan di-rollback
```

Lakukan backup sebelum menjalankan migration di server:

```sh
mysqldump --single-transaction --routines --triggers pos_live_raw > backup.sql
```

## Struktur

```
loyalty_customers 1──< loyalty_cards 1──< loyalty_stamps >──0..1 orders (POS)
loyalty_customers 1──< loyalty_redemptions >──1 loyalty_rewards
loyalty_cards     1──< loyalty_redemptions
users (POS)       1──< loyalty_stamps.created_by, loyalty_redemptions.redeemed_by
```

- **`loyalty_customers.phone_number`**: UNIQUE, disimpan dalam bentuk ternormalisasi (`6281234567890`) oleh `App\Support\WhatsAppNumber`.
- **`loyalty_customers.pin`**: hash bcrypt PIN pelanggan untuk `/verify`. `NULL` berarti masih PIN awal, yaitu 4 angka terakhir nomor HP (`6283123555526` → `5526`). Untuk reset PIN yang lupa, set kembali ke `NULL`.
- **`loyalty_cards`**: setiap pelanggan maksimal punya 1 kartu aktif. Aturan ini dijaga database lewat kolom generated `active_flag` dan unique key.
- **`loyalty_cards.expires_at`**: kartu berlaku `LOYALTY_CARD_VALID_MONTHS` bulan (default 3) sejak stamp pertamanya terbit, yaitu `created_at` stamp itu (saat dicatat sistem), bukan tanggal order (`stamped_at`). Berlaku sampai pukul 23:59:59 di hari terakhir. Contoh: stamp pertama terbit 16 November → berlaku sampai 16 Februari 23:59:59. Kalau bulan tujuannya tidak punya tanggal itu, dipakai hari terakhir bulannya (30 November → 28 Februari). Kolom ini diisi saat stamp pertama diberikan; kartu tanpa stamp nilainya `NULL`. Setelah lewat, kartu menjadi `expired` (lihat aturan stamp di bawah).
- **`loyalty_stamps.order_id`**: UNIQUE, sehingga satu order POS maksimal mendapat 1 stamp.

## Logic yang sudah ada

`App\Services\Loyalty\LoyaltyRegistrationService::registerOrRetrieve($nomorWa, $nama)` menjalankan alur pendaftaran:

1. Normalisasi nomor WhatsApp.
2. Jika nomor belum terdaftar: buat pelanggan baru dan kartu pertamanya.
3. Jika nomor sudah terdaftar: kembalikan pelanggan dan kartu aktifnya. Data pelanggan tidak diubah.
4. Jika pelanggan tidak punya kartu aktif: terbitkan kartu baru.

Semua langkah berjalan dalam satu transaksi. Pendaftaran nomor yang sama secara bersamaan tetap menghasilkan satu pelanggan dan satu kartu.

## Halaman pelanggan (`loyalty.coffeeniskala.com`)

| Rute | Fungsi |
|---|---|
| `/` | Dialihkan ke `/verify` |
| `GET /verify` | Langkah 1: form nomor HP dan Cloudflare Turnstile |
| `POST /verify` | Mencari kartu untuk nomor itu, lalu lanjut ke PIN |
| `GET /verify/pin` | Langkah 2: form PIN untuk nomor dari langkah 1 |
| `POST /verify/pin` | Mencocokkan PIN |
| `/verify-success/{customer_code}` | Kartu stamp pelanggan |
| `POST /verify-success/{customer_code}/pin` | Mengganti PIN awal |
| `GET /verify-success/{customer_code}/pin` | Form ganti PIN (menu "Ganti PIN" di bawah kartu) |
| `PUT /verify-success/{customer_code}/pin` | Mengganti PIN yang sudah pernah diganti, dengan PIN lama |

Halaman membaca `loyalty_customers` dan memeriksa token Turnstile ke Cloudflare. Halaman kartu hanya terbuka di sesi browser yang memverifikasi nomornya, dan `POST /verify`, `POST /verify/pin`, serta kedua rute ganti PIN dibatasi `throttle:10,1`. Yang tampil adalah kartu aktif, ditambah kartu penuh yang hadiahnya belum ditukar.

- **PIN:** PIN awal adalah 4 angka terakhir nomor HP. Selama PIN awal masih dipakai, kartu tampil blur (tanpa tanggal stamp) dan pelanggan wajib membuat PIN baru dulu. Setelah itu PIN bisa diganti lagi lewat menu "Ganti PIN", dengan memasukkan PIN lama. PIN salah 5 kali (di langkah PIN maupun di form ganti PIN) mengunci nomor itu selama 15 menit.
- **Verifikasi hanya mencari data, tidak mendaftarkan.** Nomor yang belum terdaftar ditolak dengan pesan. Pendaftaran tetap lewat kasir.
- **Teks untuk pelanggan memakai "nomor HP", bukan "nomor WhatsApp" atau "didaftarkan".** Pelanggan tidak mendaftar sendiri; mereka hanya menyebutkan nomor HP ke kasir saat memesan.
- **Tampilan form:** satu tema dengan kartu stamp (kelas `paper-card`). Nomor ditulis seperti pulpen di atas garis.
- **Halaman sukses tidak di-cache dan tidak diindeks** (`Cache-Control: no-store`, `noindex`).
- **Turnstile:** `.env.example` berisi kunci uji resmi Cloudflare yang selalu lolos. Untuk produksi, buat widget di dashboard Cloudflare untuk domain `loyalty.coffeeniskala.com`, lalu isi `TURNSTILE_SITE_KEY` dan `TURNSTILE_SECRET_KEY`.
- **Pengaturan produksi:** `APP_URL=https://loyalty.coffeeniskala.com`, `APP_ENV=production`, `APP_DEBUG=false`. Jalankan `npm run build` sebelum deploy.

### Tampilan kartu stamp

- **Komponen:** `<x-loyalty.stamp-card>` di `resources/views/components/loyalty/stamp-card.blade.php`, dengan style di `resources/css/app.css`.
- **Konsep:** kartu stamp cetak yang dicap barista, mengikuti desain `Kartu Loyalti Niskala 1.pdf`: kertas cokelat bata (`rust-600`, #89402D) dengan cetakan krem (`cream-100`, #FFEEDC), judul Fraunces. Halaman verifikasi/PIN memakai kertas dan warna yang sama. Hindari gradien, glow, dan efek 3D hiasan (tilt mengikuti kursor, kilau). Satu-satunya gerak 3D adalah membalik kartu, seperti kartu kertas yang dibalik.
  - Bagian cetak diam: bintang stamp, gambar hadiah di slotnya (roti kukus, gelas minuman; SVG di `public/images/card/`, diambil dari PDF), bagian "Kumpulkan", dan kotak "Pemilik kartu".
  - Yang ditambahkan orang bergerak: stiker produk ditempel satu per satu, tanggal ditulis pulpen (font Kalam), dan cap krem "Tukar" dihentakkan terakhir.
- **Interaksi:** tap atau hover slot untuk melihat keterangannya. Stiker produk bisa "dipegang": ditekan dan ditahan, stiker terangkat dari kartu; dilepas, stiker ditekan kembali sampai menempel. Script-nya di `resources/js/stamp-card.js`; tanpa JS kartu tetap tampil lengkap.
- **Voucher di belakang kartu:** kartu yang punya hadiah *Siap ditukar* punya dua sisi. Voucher (QR) dicetak di belakang. Kodenya tetap selama kartu berlaku (`NSKV1.<nomor kartu>.<stamp>.<tanda tangan>`, lihat `RewardVoucher`), dan saat dipindai kasir, POS otomatis mengisi nama dan nomor HP pelanggan. Tombol Tukar membalik kartu ke voucher hadiah itu. Ketuk sisi belakang (atau tekan Esc) untuk membaliknya lagi, dan kartu juga bisa dibalik dengan digeser ke samping. Tombol "Balik ke depan kartu" hanya untuk keyboard dan screen reader, dan baru terlihat saat difokus. Script-nya di `resources/js/card-flip.js`. Kartu tanpa hadiah siap ditukar hanya punya satu sisi dan tidak bisa dibalik. Tanpa JS, voucher tampil di bawah kartu.
- **Logo:** bunga Niskala ada di `public/images/brand/niskala-logo.png`, dibuat dari `Logo Niskala.png` di proyek `andalan-migration`.
  - Tampil lewat `<x-brand.logo class="size-12 text-rust-600" />`. File PNG dipakai sebagai mask, jadi warnanya mengikuti warna teks.
  - `public/favicon.ico` dan `public/images/brand/apple-touch-icon.png` dibuat dari logo yang sama.
- **Gambar stamp:** foto produk 400px WebP transparan di `public/images/stamps/`: latar putihnya dihapus (bayangan dan tutup gelas bening dibuat semi-transparan), jadi produk langsung menyatu dengan bintang stamp tanpa lingkaran putih. Foto baru perlu diproses dengan cara yang sama. Urutan per slot diatur di `config/loyalty.php` (`stamp_images`), dan jumlah slot di `LOYALTY_STAMPS_PER_CARD`. Slot 1–5 memakai foto roti kukus (hadiah stamp ke-5), slot 6–10 foto minuman (hadiah stamp ke-10).
- **Hadiah:** tersimpan di tabel `loyalty_rewards` dan diatur dari POS (Pengaturan → Program Promo). `config/loyalty.php` (`rewards`) hanya daftar awal. Stamp ke-5 membuka **roti kukus gratis**, stamp ke-10 membuka **minuman gratis**.
  - Kedua hadiah ditukar terpisah. Mengambil roti kukus gratis tidak mereset kartu; stamp tetap lanjut sampai 10.
  - Slot hadiah ditandai gambarnya di kartu (gelas kalau namanya minuman/kopi, roti kukus kalau roti/kue; hadiah lain tetap bintang dengan namanya). Di bawah grid ada bagian "Kumpulkan" (*5 stamp untuk Roti Kukus GRATIS*), dengan status tiap hadiah di bawahnya: *x stamp lagi*, *Siap diambil* + tombol Tukar, atau *Sudah ditukar* / tanggal ditukar.
  - Hadiah yang sudah diambil dikirim ke komponen lewat `:redeemed-rewards="[5]"`.
- **Pratinjau:** semua keadaan kartu bisa dilihat di `/preview/stamp-card`. Rute ini hanya ada di environment `local`.

## API untuk POS (`/api/pos/*`)

Dipanggil oleh pos-backend dari server ke server, tidak pernah dari browser. Header: `Authorization: Bearer <LOYALTY_POS_API_TOKEN>`. Nilai yang sama dipasang di `LOYALTY_API_TOKEN` pada `pos-backend/.env`.

| Endpoint | Dipanggil saat | Hasil |
|---|---|---|
| `GET /api/pos/customers/{phone}` | Kasir mengetik nomor HP | `registered`, kartu (`stamps`/`slots`), `ready_rewards`, `next_reward`, `stamp_min_order_total`. Tidak mendaftarkan nomor |
| `POST /api/pos/stamps` `{order_id, cashier_id?}` | Setelah order tersimpan | 201 untuk stamp baru, 200 kalau order sudah pernah dapat stamp. Nomor baru didaftarkan di sini |
| `POST /api/pos/redemptions` `{phone, reward_stamps, cashier_id?}` | Kasir menukar hadiah | 201. Diambil dari kartu terlama yang hadiahnya siap |

Penolakan dikirim sebagai `{message, reason}` dengan status 4xx. Contoh `reason`: `no_phone`, `order_type`, `below_minimum`, `invalid_phone`, `reward_not_ready`, `not_registered`.

**Aturan stamp (sementara, di `config/loyalty.php`):**

- Order POS bertipe `Offline` (hanya tipe ini yang menyimpan nomor HP), punya nomor HP, dan `total_with_tax` (yang dibayar pelanggan) ≥ `LOYALTY_STAMP_MIN_ORDER_TOTAL` (default Rp 100.000). Status order tidak diperiksa.
- Data order dibaca dari tabel `orders`. POS cukup mengirim `order_id`.
- Satu order dapat satu stamp. Kartu yang mencapai `LOYALTY_STAMPS_PER_CARD` stamp menjadi `completed`, dan stamp berikutnya masuk ke kartu baru (`-02`).
- Kartu `completed` berubah menjadi `redeemed` setelah semua hadiahnya ditukar. Baris `loyalty_rewards` dibuat saat hadiah itu pertama kali ditukar (`stamps_required`).
- **Kedaluwarsa:** kartu `active` atau `completed` yang lewat `expires_at` menjadi `expired`. Stamp di kartu itu tidak dihitung lagi, jadi hitungannya mulai dari 0. Order berikutnya menjadi stamp 1 di kartu baru (`-02`, dan seterusnya) dengan masa berlaku 3 bulan yang baru. Hadiah yang belum ditukar di kartu itu hangus: tidak muncul di `ready_rewards`, penukarannya ditolak (`reward_not_ready`), dan voucher QR-nya berstatus `expired`.
  - Statusnya diperbarui saat kartu dipakai (stamp, penukaran, halaman kartu, scan voucher, cek nomor di POS). Selain itu `php artisan loyalty:expire-cards` dijalankan tiap jam lewat scheduler.
  - Di halaman pelanggan, kartu tampil dengan cap "Kedaluwarsa", tulisan "Berakhir <tanggal>", dan catatan bahwa stamp mulai lagi dari 0 di order berikutnya.

**Stamp yang terlewat:** pos-backend tidak menunggu jawaban loyalty saat menyimpan order. `php artisan loyalty:sync-stamps` memberi stamp ke order ber-nomor HP dalam `LOYALTY_SYNC_DAYS` hari terakhir yang belum dapat stamp. Command ini dijadwalkan tiap 5 menit, jadi produksi butuh cron `* * * * * php artisan schedule:run`.

**Lokal:** jalankan `php artisan serve` (port 8000). pos-backend memakai `LOYALTY_API_URL=http://127.0.0.1:8000/api/pos`. `DB_DATABASE` di sini harus sama dengan `DB_NAME` pos-backend.

**Produksi:** `LOYALTY_API_URL=https://loyalty.coffeeniskala.com/api/pos`, dengan token baru di kedua `.env`. Kalau domain ini diproteksi Cloudflare, buat WAF rule *skip* untuk path `/api/pos/*`, karena server tidak bisa melewati bot challenge.

**Belum dibuat (menunggu spesifikasi bisnis):**

- item hadiah gratis di order POS (harga 0, stok dan HPP tetap terhitung). Saat ini tombol Tukar di kasir hanya mencatat penukaran di loyalty;
- aturan status order, minimal belanja, dan batas stamp per hari yang final.
