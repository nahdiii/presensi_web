# Presensi Kampus — Backend & Panel Admin

Satu backend Laravel melayani **panel admin web (Filament)** untuk Bagian SDM dan
pimpinan unit, serta **API `/api/v1`** untuk aplikasi Flutter dosen dan tendik
(`../../Mobile/presensi_mobile`). Server adalah satu-satunya sumber kebenaran: jam
absen, validasi lokasi, dan status diputuskan di sini.

## Menjalankan secara lokal

PHP tidak perlu dipasang di Mac; semuanya berjalan di Docker.

```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
```

- Panel admin: http://localhost:8090/admin
- API: http://localhost:8090/api/v1
- Dokumentasi OpenAPI (Scramble): http://localhost:8090/docs/api
- Postgres: `localhost:5434` (user/password/db `presensi`)

Akun contoh ada di `database/seeders/DatabaseSeeder.php` (admin SDM, pimpinan
Fakultas Teknik, tendik, dosen). Koordinat kampus di seeder hanya perkiraan.

Service `worker` (antrean kompres foto) dan `scheduler` (tutup hari presensi
pukul 23.50 WITA) ikut berjalan lewat `docker compose up`.

## Test

```bash
docker compose exec app php artisan test
```

`tests/Feature/CheckInTest.php` memuat semua skenario wajib dari blueprint
(tepat waktu, terlambat, di luar radius, fake GPS, akurasi rendah, perangkat
asing, dobel absen, shift lewat tengah malam, jam request diabaikan, policy),
ditambah test jadwal, login/ikat perangkat, alpa otomatis, izin, dan smoke test
semua halaman admin.

## Struktur penting

| Lokasi | Isi |
| --- | --- |
| `app/Services/AttendanceService.php` | `checkIn()`, `checkOut()`, `today()`: semua aturan absen dan jejak audit |
| `app/Services/ShiftResolver.php` | Jadwal paling spesifik per pegawai per tanggal (override → kalender → pola pegawai → unit → induk → default) |
| `app/Services/GeofenceService.php` | Haversine dan lokasi terdekat |
| `app/Console/Commands/CloseAttendanceDay.php` | `attendance:close-day`: alpa, tidak absen pulang, izin, libur |
| `app/Exports/MonthlyAttendanceExport.php` | Rekap bulanan .xlsx (ringkasan + rincian harian) |
| `app/Filament/` | Resource admin, widget dashboard |
| `routes/api.php` | Endpoint Flutter |

Format respons API: sukses `{ "data": … }`, gagal `{ "error": { "code", "message" } }`
(422 validasi/penolakan, 403 perangkat asing atau akun nonaktif). Kode penolakan:
lihat `app/Enums/RejectReason.php`.

## Peran

- `admin_sdm`: semua menu, termasuk pegawai, lokasi, pola jadwal, reset perangkat.
- `pimpinan_unit`: dashboard, presensi, izin, dan log untuk unit yang ia kepalai
  (beserta sub-unit); menyetujui izin.
- `pegawai`: hanya aplikasi mobile.

## Deploy (Railway)

`Dockerfile` di root adalah image produksi (FrankenPHP). Buat tiga service dari
repo yang sama: **web** (CMD bawaan), **worker** (`php artisan queue:work --tries=3
--backoff=5`), **scheduler** (`php artisan schedule:work`), ditambah PostgreSQL
Railway. Pre-deploy command: `php artisan migrate --force`. Foto wajib disimpan di
Cloudflare R2/S3 (`ATTENDANCE_PHOTO_DISK=s3`, isian `AWS_*` ada di `.env.example`)
karena disk container hilang setiap redeploy.

## Cakupan versi ini

Sudah: seluruh fitur **MVP (Fase 1)** di blueprint, plus pengajuan izin/sakit/cuti/
tugas luar dengan persetujuan pimpinan dan endpoint token FCM. Belum: izin keluar
sementara, cek kehadiran acak, koreksi lupa absen, kirim notifikasi FCM, kalender
akademik lanjutan (Fase 2), dan seluruh Fase 3.
