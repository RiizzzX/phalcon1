<<<<<<< HEAD
simple phalcon manajemnen inventory
=======
# Simple Inventory Tracker (Phalcon)

Panduan singkat cara install dan menjalankan aplikasi ini, serta cara mengimpor SQL.

> Lokasi penting
- Kode aplikasi: `app/`
- Public web root: `public/`
- SQL inisialisasi: `init.sql`
- Docker compose: `docker-compose.yml`

## Opsi 1 — Jalankan dengan Docker (Direkomendasikan)
Cara ini paling mudah: akan menjalankan web server, MySQL, dan phpMyAdmin.

1. Pastikan [Docker Desktop](https://www.docker.com/products/docker-desktop) sudah terpasang dan berjalan.
2. Buka terminal di folder proyek (di workspace):

```powershell
cd C:\Users\ACER\ilmu_pkl\new
```

3. Jalankan layanan dengan Docker Compose:

```powershell
docker-compose up -d --build
```

4. Akses aplikasi di: http://localhost:8080
   - phpMyAdmin: http://localhost:8081 (user: `root`, password: `secret`)

5. Database dan tabel awal otomatis dibuat dari `init.sql` yang di-mount ke container MySQL (lihat `docker-compose.yml`).

6. Untuk melihat log:

```powershell
docker-compose logs -f
```

7. Hentikan dan hapus container jika perlu:

```powershell
docker-compose down
```

## Opsi 2 — Jalankan secara lokal (tanpa Docker)
Prerequisites:
- PHP 8+ dengan ekstensi Phalcon terpasang (sesuaikan versi Phalcon dengan kode),
- Composer,
- MySQL atau MariaDB.

Langkah singkat:

1. Install dependensi (jika ada `composer.json`):

```powershell
cd C:\Users\ACER\ilmu_pkl\new
composer install
```

2. Buat database di MySQL, contoh:

```sql
CREATE DATABASE phalcon_db;
```

3. Import `init.sql` ke database (cara CLI):

```powershell
mysql -u root -p phalcon_db < init.sql
```

atau dengan phpMyAdmin atau tool lain.

4. Konfigurasi koneksi database di aplikasi jika diperlukan: cek `app/config/config.php` atau `app/config/services.php` untuk mengubah host/user/password/database.

5. Jalankan built-in PHP server (untuk development):

```powershell
php -S localhost:8080 -t public
```

# Simple Inventory Tracker (Phalcon)

Panduan singkat cara install dan menjalankan aplikasi ini, serta cara mengimpor SQL.

> Lokasi penting
- Kode aplikasi: `app/`
- Public web root: `public/`
- SQL inisialisasi: `init.sql`
- Docker compose: `docker-compose.yml`

## Opsi 1 — Jalankan dengan Docker (Direkomendasikan)
Cara ini paling mudah: akan menjalankan web server, MySQL, dan phpMyAdmin.

1. Pastikan [Docker Desktop](https://www.docker.com/products/docker-desktop) sudah terpasang dan berjalan.
2. Buka terminal di folder proyek (di workspace):

```powershell
cd C:\Users\ACER\ilmu_pkl\new
```

3. Jalankan layanan dengan Docker Compose:

```powershell
docker-compose up -d --build
```

4. Akses aplikasi di: http://localhost:8080
   - phpMyAdmin: http://localhost:8081 (user: `root`, password: `secret`)

5. Database dan tabel awal otomatis dibuat dari `init.sql` yang di-mount ke container MySQL (lihat `docker-compose.yml`).

6. Untuk melihat log:

```powershell
docker-compose logs -f
```

7. Hentikan dan hapus container jika perlu:

```powershell
docker-compose down
```

## Opsi 2 — Jalankan secara lokal (tanpa Docker)
Prerequisites:
- PHP 8+ dengan ekstensi Phalcon terpasang (sesuaikan versi Phalcon dengan kode),
- Composer,
- MySQL atau MariaDB.

Langkah singkat:

1. Install dependensi (jika ada `composer.json`):

```powershell
cd C:\Users\ACER\ilmu_pkl\new
composer install
```

2. Buat database di MySQL, contoh:

```sql
CREATE DATABASE phalcon_db;
```

3. Import `init.sql` ke database (cara CLI):

```powershell
mysql -u root -p phalcon_db < init.sql
```

atau dengan phpMyAdmin atau tool lain.

4. Konfigurasi koneksi database di aplikasi jika diperlukan: cek `app/config/config.php` atau `app/config/services.php` untuk mengubah host/user/password/database.

5. Jalankan built-in PHP server (untuk development):

```powershell
php -S localhost:8080 -t public
```

Lalu buka http://localhost:8080

> Catatan: pengaturan web server produksi (Nginx/Apache) perlu menyesuaikan document root ke folder `public/`.

## Cara import SQL (detail)
`init.sql` sudah berisi perintah membuat table `users` dan beberapa data contoh. Jika menggunakan Docker, file ini otomatis dijalankan saat container MySQL pertama kali dibuat (karena di-mount ke `/docker-entrypoint-initdb.d/init.sql`).

Jika perlu mengimpor ulang (MySQL sudah berisi data):

1. Hapus data atau drop database lalu buat ulang:

```sql
DROP DATABASE IF EXISTS phalcon_db;
CREATE DATABASE phalcon_db;
```

2. Import SQL:

```powershell
mysql -u root -p phalcon_db < init.sql
```

atau melalui phpMyAdmin: pilih database `phalcon_db` → Import → pilih file `init.sql` → Go.

## Troubleshooting singkat
- Jika menggunakan Docker dan perubahan kode tidak muncul, pastikan volume mount aktif dan Anda me-reload container `phalcon5` atau rebuild.
- Jika muncul error koneksi DB, cek `docker-compose.yml` environment (user/password) dan pengaturan koneksi di aplikasi.

---
Jika Anda mau, saya bisa:
- Menambahkan contoh konfigurasi `app/config` untuk koneksi MySQL, atau
- Menambahkan badge/status CI, atau
- Membuat skrip shell sederhana untuk mempermudah start/stop.

File README disimpan di: [README.md](README.md)
