<?php

namespace Database\Seeders;

use App\Enums\AttendanceType;
use App\Enums\CalendarEventType;
use App\Enums\EmployeeType;
use App\Enums\UnitType;
use App\Enums\WorkPatternType;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Office;
use App\Models\TeachingSchedule;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkPattern;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Data contoh untuk pengembangan lokal. Semua akun berpassword "password".
 *
 *   admin@kampus.test     Admin SDM (panel /admin)
 *   dekan.ft@kampus.test  Pimpinan unit Fakultas Teknik (panel + aplikasi)
 *   tendik@kampus.test    Tendik Biro SDM, presensi tetap (aplikasi)
 *   dosen@kampus.test     Dosen Informatika, presensi fleksibel + 2 jadwal mengajar (aplikasi)
 *   dosen.sesi@kampus.test Dosen Informatika, presensi per sesi mengajar (aplikasi)
 *   demo1..12@kampus.test Pegawai contoh berisi riwayat 2 minggu (hanya di lokal)
 *
 * Koordinat kampus hanya perkiraan; Bagian SDM perlu menyesuaikannya.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_HEAD, User::ROLE_EMPLOYEE] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $inst = Institution::create(['name' => 'Universitas Muhammadiyah Banjarmasin', 'timezone' => 'Asia/Makassar']);

        $tendik = $this->pattern($inst, 'Tendik Reguler', WorkPatternType::Fixed, tolerance: 15, open: 60, days: [
            1 => ['07:30', '16:00', '12:00', '13:00'],
            2 => ['07:30', '16:00', '12:00', '13:00'],
            3 => ['07:30', '16:00', '12:00', '13:00'],
            4 => ['07:30', '16:00', '12:00', '13:00'],
            5 => ['07:30', '16:30', '11:30', '13:30'], // jeda salat Jumat
        ]);
        $dosen = $this->pattern($inst, 'Dosen Fleksibel', WorkPatternType::Flexible, open: 0, minDaily: 360, days: [
            1 => ['06:00', '21:00'], 2 => ['06:00', '21:00'], 3 => ['06:00', '21:00'],
            4 => ['06:00', '21:00'], 5 => ['06:00', '21:00'],
        ]);
        $this->pattern($inst, 'Satpam Malam', WorkPatternType::Fixed, tolerance: 10, open: 30, days: array_fill(1, 7, ['22:00', '06:00']));
        $inst->update(['default_work_pattern_id' => $tendik->id]);

        $kampus1 = Office::create([
            'institution_id' => $inst->id, 'name' => 'Kampus 1',
            'address' => 'Jl. S. Parman, Banjarmasin', 'latitude' => -3.3152000, 'longitude' => 114.5883000,
            'radius_m' => 150, 'timezone' => 'Asia/Makassar',
        ]);
        $kampus2 = Office::create([
            'institution_id' => $inst->id, 'name' => 'Kampus 2 (Gedung Teknik)',
            'address' => 'Banjarmasin', 'latitude' => -3.2985000, 'longitude' => 114.6012000,
            'radius_m' => 100, 'timezone' => 'Asia/Makassar',
        ]);

        $ft = Unit::create(['institution_id' => $inst->id, 'name' => 'Fakultas Teknik', 'type' => UnitType::Fakultas, 'work_pattern_id' => $tendik->id]);
        $if = Unit::create(['institution_id' => $inst->id, 'parent_id' => $ft->id, 'name' => 'Prodi Informatika', 'type' => UnitType::Prodi]);
        $bsdm = Unit::create(['institution_id' => $inst->id, 'name' => 'Biro Sumber Daya Manusia', 'type' => UnitType::Biro, 'work_pattern_id' => $tendik->id]);

        $admin = User::create(['institution_id' => $inst->id, 'name' => 'Admin SDM', 'email' => 'admin@kampus.test', 'password' => 'password']);
        $admin->assignRole(User::ROLE_ADMIN);

        $dekan = $this->employee($inst, 'Dr. Rahmat Hidayat, M.T.', 'dekan.ft@kampus.test', '197801012005011001', $ft,
            EmployeeType::Dosen, AttendanceType::Flexible, $dosen, [$kampus1, $kampus2], nidn: '1101017801');
        $dekan->user->assignRole(User::ROLE_HEAD);
        $ft->update(['head_employee_id' => $dekan->id]);

        $this->employee($inst, 'Siti Aminah, S.E.', 'tendik@kampus.test', '199003152015042001', $bsdm,
            EmployeeType::Tendik, AttendanceType::Fixed, null, [$kampus1]);
        $fauzi = $this->employee($inst, 'Ahmad Fauzi, M.Kom.', 'dosen@kampus.test', '198805202014041002', $if,
            EmployeeType::Dosen, AttendanceType::Flexible, $dosen, [$kampus1, $kampus2], nidn: '1120058801');
        $nur = $this->employee($inst, 'Nur Halimah, M.T.', 'dosen.sesi@kampus.test', '199101102019032005', $if,
            EmployeeType::Dosen, AttendanceType::Session, null, [$kampus1, $kampus2], nidn: '1110019101');
        $this->teaching($inst, $if, $kampus2, $fauzi, $nur);
        $this->employee($inst, 'Budi Santoso', 'tendik.ft@kampus.test', '199207012019031003', $ft,
            EmployeeType::Tendik, AttendanceType::Fixed, null, [$kampus2]);

        CalendarEvent::create(['institution_id' => $inst->id, 'date' => '2026-12-25', 'name' => 'Hari Raya Natal', 'type' => CalendarEventType::LiburNasional]);
        CalendarEvent::create(['institution_id' => $inst->id, 'date' => '2026-12-24', 'name' => 'Cuti bersama Natal', 'type' => CalendarEventType::CutiBersama]);

        if (app()->environment('local')) {
            $this->call(DemoAttendanceSeeder::class);
        }
    }

    /** Jadwal mengajar semester ganjil 2026/2027; dosen.sesi punya kuliah setiap hari kerja supaya mudah dicoba. */
    private function teaching(Institution $inst, Unit $prodi, Office $gedung, Employee $fauzi, Employee $nur): void
    {
        $course = fn (string $code, string $name, int $credits) => Course::create([
            'institution_id' => $inst->id, 'unit_id' => $prodi->id, 'code' => $code, 'name' => $name, 'credits' => $credits,
        ]);
        $web = $course('IF201', 'Pemrograman Web', 3);
        $basisData = $course('IF203', 'Basis Data', 3);
        $jarkom = $course('IF305', 'Jaringan Komputer', 2);
        $algo = $course('IF101', 'Algoritma dan Pemrograman', 4);

        $slot = fn (Employee $e, Course $c, string $class, int $weekday, string $start, string $end, ?string $room) => TeachingSchedule::create([
            'institution_id' => $inst->id, 'employee_id' => $e->id, 'course_id' => $c->id, 'class_name' => $class,
            'weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'office_id' => $gedung->id, 'room' => $room,
            'valid_from' => '2026-09-01', 'valid_until' => '2027-01-31',
        ]);
        $slot($fauzi, $web, 'A', 1, '08:00', '10:30', 'R. 2.04');
        $slot($fauzi, $basisData, 'B', 3, '13:00', '15:30', 'Lab. Basis Data');
        foreach (range(1, 5) as $wd) {
            $slot($nur, $algo, 'A', $wd, '08:00', '09:40', 'R. 1.02');
            $slot($nur, $jarkom, 'B', $wd, '10:00', '11:40', 'Lab. Jaringan');
            $slot($nur, $algo, 'C', $wd, '14:00', '15:40', 'R. 1.03');
        }
    }

    /** @param array<int, array{0: string, 1: string, 2?: string, 3?: string}> $days */
    private function pattern(Institution $inst, string $name, WorkPatternType $type, array $days, int $tolerance = 0, int $open = 60, ?int $minDaily = null): WorkPattern
    {
        $pattern = WorkPattern::create([
            'institution_id' => $inst->id, 'name' => $name, 'type' => $type,
            'late_tolerance_min' => $tolerance, 'checkin_open_min' => $open, 'min_daily_minutes' => $minDaily,
        ]);
        foreach (range(1, 7) as $wd) {
            $d = $days[$wd] ?? null;
            $pattern->days()->create([
                'weekday' => $wd,
                'start_time' => $d[0] ?? null,
                'end_time' => $d[1] ?? null,
                'break_start' => $d[2] ?? null,
                'break_end' => $d[3] ?? null,
                'is_day_off' => $d === null,
            ]);
        }

        return $pattern;
    }

    private function employee(Institution $inst, string $name, string $email, string $nip, Unit $unit, EmployeeType $type,
        AttendanceType $attendance, ?WorkPattern $pattern, array $offices, ?string $nidn = null): Employee
    {
        $user = User::create(['institution_id' => $inst->id, 'name' => $name, 'email' => $email, 'password' => 'password']);
        $user->assignRole(User::ROLE_EMPLOYEE);
        $employee = Employee::create([
            'institution_id' => $inst->id, 'user_id' => $user->id, 'nip' => $nip, 'nidn' => $nidn,
            'employee_type' => $type, 'attendance_type' => $attendance, 'unit_id' => $unit->id,
            'work_pattern_id' => $pattern?->id,
        ]);
        $employee->offices()->attach(collect($offices)->pluck('id'));

        return $employee;
    }
}
