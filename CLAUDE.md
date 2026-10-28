# Presensi Kampus — backend & panel admin

Laravel 13 + Filament 5 + Sanctum + spatie/laravel-permission, PostgreSQL. Lihat README.md.

- PHP dan Composer **tidak** terpasang di Mac; semua perintah lewat Docker:
  `docker compose exec app php artisan …`, `docker compose exec app composer …`.
- Test memakai database Postgres `presensi_test` (lihat phpunit.xml):
  `docker compose exec app php artisan test`.
- Semua aturan absen ada di `app/Services/AttendanceService.php`; controller tipis.
  Setiap aturan penolakan wajib punya test di `tests/Feature/CheckInTest.php`.
- Closure Filament disuntik berdasarkan **nama** parameter: pakai `$query`, `$data`,
  `$record`, `$get`, `$set` — nama lain (mis. `$q`) menghasilkan objek kosong.
