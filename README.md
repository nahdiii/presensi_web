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

Service `worker` (antrean kompres foto) dan `scheduler` ikut berjalan lewat
`docker compose up`. Jadwalnya: kirim cek kehadiran acak (tiap menit),
pengingat absen (tiap 5 menit), tutup hari presensi (23.50 WITA), dan backup
database (02.00 WITA).

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
| `app/Services/PresenceCheckService.php` | Cek kehadiran acak: jadwal 1–3 kali/hari, kirim, jawab, tidak merespons |
| `app/Services/ExitPermitService.php` | Izin keluar sementara (keluar → kembali di radius) |
| `app/Services/CorrectionService.php` | Koreksi lupa absen; diterapkan saat disetujui |
| `app/Notifications/Push/` | Notifikasi FCM; tanpa kredensial hanya dicatat di log |
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

## Notifikasi (Firebase Cloud Messaging)

Backend siap mengirim push untuk cek kehadiran acak, pengingat absen, dan
keputusan izin/koreksi. Untuk mengaktifkannya:

1. Buat proyek di Firebase Console, daftarkan aplikasi Android
   (`com.nahdi.presensi_mobile`) dan iOS (`com.nahdi.presensiMobile`).
2. Unduh service account JSON (Project settings → Service accounts), simpan
   di server, lalu isi `FCM_CREDENTIALS=/path/ke/berkas.json`.
3. Di aplikasi: tambahkan `firebase_core` + `firebase_messaging`, berkas
   `google-services.json` / `GoogleService-Info.plist`, lalu kirim token ke
   `POST /api/v1/devices/fcm-token`.

Tanpa FCM, cek kehadiran acak tetap muncul di beranda aplikasi karena beranda
menyegarkan status setiap menit selama aplikasi terbuka.

## Backup

`php artisan backup:database` membuat `pg_dump` ke disk `BACKUP_DISK`
(default `local` → `storage/app/private/db`; produksi: bucket R2 terpisah) dan
menyimpan 14 berkas terbaru. Pulihkan dengan
`pg_restore --clean --no-owner -d <database> <berkas.dump>`.

## Cakupan versi ini

Sudah: seluruh **Fase 1 (MVP)** dan **Fase 2**: izin/sakit/cuti/tugas luar, izin
keluar sementara, cek kehadiran acak, koreksi lupa absen, pengingat dan
notifikasi (perlu kredensial Firebase), laporan pola di dasbor pimpinan, dan
backup harian. Belum: Fase 3 (presensi per jadwal mengajar/SIAKAD, verifikasi
Wi-Fi, geofence latar belakang, pencocokan wajah, multi-institusi & langganan).
