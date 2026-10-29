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
Fakultas Teknik, tendik, dosen, dan `dosen.sesi@kampus.test` yang presensinya per sesi
mengajar). Koordinat kampus di seeder hanya perkiraan.

Service `worker` (antrean kompres foto) dan `scheduler` ikut berjalan lewat
`docker compose up`. Jadwalnya: kirim cek kehadiran acak (tiap menit),
pengingat absen dan sesi kuliah (tiap 5 menit), tutup hari presensi dan sesi
mengajar (23.50 WITA), dan backup database (02.00 WITA).

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
| `app/Services/TeachingSessionService.php` | Presensi per sesi mengajar: jadwal hari ini, absen sesi, tutup sesi yang terlewat |
| `app/Services/TeachingScheduleImporter.php` | Impor jadwal mengajar dari CSV (`,` atau `;`) |
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
3. Di aplikasi: letakkan `google-services.json` / `GoogleService-Info.plist`
   (lihat README presensi_mobile). Aplikasi mengirim token ke
   `POST /api/v1/devices/fcm-token` setelah login; logout menghapus token itu.

Tanpa FCM, cek kehadiran acak tetap muncul di beranda aplikasi karena beranda
menyegarkan status setiap menit selama aplikasi terbuka.

## Presensi per sesi mengajar (Fase 3)

Menu **Perkuliahan** di panel admin:

- **Mata Kuliah** dan **Jadwal Mengajar**: jadwal mingguan per dosen selama
  rentang semester, dengan gedung (opsional) dan ruang. Isi manual atau tombol
  **Impor CSV** (kolom: `nip, kode_mk, nama_mk, sks, kelas, hari, mulai, selesai,
  ruang, lokasi, berlaku_mulai, berlaku_sampai`; contoh lewat tombol **Contoh CSV**).
  Impor ulang memperbarui jadwal yang sama, tidak menduplikasi.
- **Sesi Mengajar**: hasil absen per sesi (tepat waktu, terlambat, tidak hadir,
  izin/sakit/tugas luar, libur) beserta selfie, lokasi, dan pokok bahasan.
  Pimpinan unit melihat dosen di unitnya. Rekap bulanan .xlsx mendapat lembar
  *Sesi mengajar*.

Aturan: absen sesi dibuka 15 menit sebelum kuliah sampai jam selesai, terlambat
bila lewat 15 menit dari jam mulai (`config/attendance.php` → `sessions`). Bila
jadwal punya gedung, absen harus di radius gedung itu. Sesi yang lewat tanpa absen
ditandai tidak hadir oleh `attendance:close-day`, kecuali hari libur kalender atau
izin yang disetujui. Pegawai bertipe presensi **Per sesi mengajar** tidak absen
harian; dosen bertipe lain tetap absen harian dan juga melihat jadwal mengajarnya.

API: `GET /teaching/today`, `POST /teaching/{jadwal}/check-in` (bukti absen +
`topic` opsional), `GET /teaching/history?month=YYYY-MM`. Jadwal hari ini juga
ikut di `GET /attendance/today` sebagai `teaching`.

## Backup

`php artisan backup:database` membuat `pg_dump` ke disk `BACKUP_DISK`
(default `local` → `storage/app/private/db`; produksi: bucket R2 terpisah) dan
menyimpan 14 berkas terbaru. Pulihkan dengan
`pg_restore --clean --no-owner -d <database> <berkas.dump>`.

## Cakupan versi ini

Sudah: seluruh **Fase 1 (MVP)** dan **Fase 2**: izin/sakit/cuti/tugas luar, izin
keluar sementara, cek kehadiran acak, koreksi lupa absen, pengingat dan
notifikasi (perlu kredensial Firebase), laporan pola di dasbor pimpinan, dan
backup harian. Dari Fase 3: presensi per sesi mengajar (jadwal manual/CSV).
Belum: sinkronisasi SIAKAD, verifikasi Wi-Fi, geofence latar belakang,
pencocokan wajah, multi-institusi & langganan.
