<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\EmployeeType;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceAttempt;
use App\Models\Employee;
use App\Models\ExitPermit;
use App\Models\Institution;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\PresenceCheck;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkPattern;
use App\Services\ShiftResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Data contoh hanya untuk lokal: tambahan pegawai dan riwayat presensi dua
 * minggu terakhir supaya dashboard dan rekap terlihat terisi.
 */
class DemoAttendanceSeeder extends Seeder
{
    private const NAMES = [
        ['Nurul Hidayah, S.Pd.', 'tendik'], ['Muhammad Rizki', 'tendik'], ['Fitriani, S.Kom.', 'tendik'],
        ['Hendra Saputra', 'tendik'], ['Dewi Lestari, S.E.', 'tendik'], ['Rina Marlina', 'tendik'],
        ['Dr. Agus Salim, M.Pd.', 'dosen'], ['Yuliana Sari, M.T.', 'dosen'], ['Ir. Bambang Wijaya, M.Eng.', 'dosen'],
        ['Siti Rahmah, M.Kom.', 'dosen'], ['Arif Rahman, M.Cs.', 'dosen'], ['Lina Wati, M.Ak.', 'dosen'],
    ];

    public function run(): void
    {
        mt_srand(42);
        $inst = Institution::firstOrFail();
        $tz = config('attendance.timezone');
        $units = Unit::all();
        $offices = Office::all();
        $flex = WorkPattern::where('name', 'Dosen Fleksibel')->first();

        foreach (self::NAMES as $i => [$name, $type]) {
            $user = User::create([
                'institution_id' => $inst->id, 'name' => $name, 'password' => 'password',
                'email' => 'demo'.($i + 1).'@kampus.test',
            ]);
            $user->assignRole(User::ROLE_EMPLOYEE);
            $isDosen = $type === 'dosen';
            $e = Employee::create([
                'institution_id' => $inst->id, 'user_id' => $user->id, 'nip' => '1990'.str_pad((string) ($i + 1), 14, '0', STR_PAD_LEFT),
                'employee_type' => $isDosen ? EmployeeType::Dosen : EmployeeType::Tendik,
                'attendance_type' => $isDosen ? AttendanceType::Flexible : AttendanceType::Fixed,
                'unit_id' => $units[$i % $units->count()]->id,
                'work_pattern_id' => $isDosen ? $flex?->id : null,
            ]);
            $e->offices()->attach($offices->pluck('id'));
        }

        $shifts = app(ShiftResolver::class);
        $today = CarbonImmutable::now($tz)->startOfDay();
        // Dosen presensi per sesi tidak punya presensi harian.
        $employees = Employee::with(['user', 'offices'])->where('attendance_type', '!=', AttendanceType::Session)->get();

        foreach (range(14, 0) as $back) {
            $date = $today->subDays($back);
            $d = $date->toDateString();
            foreach ($employees as $e) {
                $s = $shifts->resolve($e, $d);
                if ($s->isDayOff) {
                    continue;
                }
                $office = $e->offices->first();
                $roll = mt_rand(1, 100);
                // Hari ini: sebagian sudah absen, sebagian belum.
                if ($back === 0 && $roll > 70) {
                    continue;
                }
                $status = match (true) {
                    $roll <= 68 => AttendanceStatus::Present,
                    $roll <= 82 => AttendanceStatus::Late,
                    $roll <= 87 => AttendanceStatus::Incomplete,
                    $roll <= 91 => AttendanceStatus::Leave,
                    $roll <= 94 => AttendanceStatus::Sick,
                    $roll <= 96 => AttendanceStatus::Duty,
                    default => AttendanceStatus::Absent,
                };
                if ($back === 0 && $status === AttendanceStatus::Incomplete) {
                    $status = AttendanceStatus::Present;
                }
                // Supaya daftar "Terlambat hari ini" punya isi.
                if ($back === 0 && ! $s->isFlexible() && $roll > 45 && $roll <= 68) {
                    $status = AttendanceStatus::Late;
                }
                $row = ['institution_id' => $inst->id, 'employee_id' => $e->id, 'date' => $d,
                    'status' => $status, 'schedule_snapshot' => $s->toArray()];

                if (in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Incomplete], true)) {
                    $start = $s->startAt($d, $tz);
                    $in = $status === AttendanceStatus::Late
                        ? $start->addMinutes($s->lateToleranceMin + mt_rand(1, 40))
                        : $start->subMinutes(mt_rand(0, 25))->addMinutes($s->isFlexible() ? mt_rand(60, 180) : 0);
                    $late = $status === AttendanceStatus::Late && ! $s->isFlexible() ? (int) $start->diffInMinutes($in) : 0;
                    if ($s->isFlexible() && $status === AttendanceStatus::Late) {
                        $row['status'] = AttendanceStatus::Present;
                    }
                    $row += [
                        'late_minutes' => $late, 'check_in_office_id' => $office->id, 'check_in_at' => $in->utc(),
                        'check_in_lat' => $office->latitude + mt_rand(-40, 40) / 100000, 'check_in_lng' => $office->longitude + mt_rand(-40, 40) / 100000,
                        'check_in_accuracy_m' => mt_rand(5, 25), 'check_in_distance_m' => mt_rand(5, 80),
                    ];
                    if ($status !== AttendanceStatus::Incomplete && $back > 0) {
                        $out = $s->isFlexible() ? $in->addMinutes(mt_rand(300, 480)) : $s->endAt($d, $tz)->addMinutes(mt_rand(-20, 40));
                        $worked = (int) $in->diffInMinutes($out) - $s->breakOverlapMinutes($d, $tz, $in, $out);
                        $row += [
                            'check_out_office_id' => $office->id, 'check_out_at' => $out->utc(),
                            'check_out_lat' => $row['check_in_lat'], 'check_out_lng' => $row['check_in_lng'],
                            'check_out_accuracy_m' => mt_rand(5, 25), 'check_out_distance_m' => mt_rand(5, 80),
                            'worked_minutes' => max(0, $worked),
                            'is_early_leave' => $s->isFlexible() ? $worked < ($s->minDailyMinutes ?? 0) : $out->lt($s->endAt($d, $tz)),
                        ];
                    }
                }
                Attendance::create($row);

                if (mt_rand(1, 100) <= 6) {
                    AttendanceAttempt::create([
                        'employee_id' => $e->id, 'type' => 'in', 'result' => 'rejected',
                        'reason' => ['out_of_radius', 'low_accuracy', 'mock_location'][mt_rand(0, 2)],
                        'office_id' => $office->id, 'lat' => $office->latitude + 0.004, 'lng' => $office->longitude,
                        'distance_m' => mt_rand(150, 900), 'accuracy_m' => mt_rand(8, 90), 'is_mocked' => false,
                        'created_at' => $s->startAt($d, $tz)->subMinutes(mt_rand(1, 30))->utc(),
                    ]);
                }
            }
        }

        $this->seedPhaseTwo($employees, $today, $tz);

        foreach ($employees->random(4) as $i => $e) {
            LeaveRequest::create([
                'institution_id' => $inst->id, 'employee_id' => $e->id,
                'type' => [LeaveType::Cuti, LeaveType::Sakit, LeaveType::TugasLuar, LeaveType::Izin][$i],
                'start_date' => $today->addDays($i + 1)->toDateString(), 'end_date' => $today->addDays($i + 2)->toDateString(),
                'reason' => ['Cuti tahunan keperluan keluarga', 'Rawat jalan pasca operasi', 'Seminar nasional di Surabaya', 'Mengurus dokumen kependudukan'][$i],
                'status' => LeaveStatus::Pending,
            ]);
        }
    }

    /** Contoh cek acak, izin keluar, dan koreksi supaya halaman admin fase 2 terisi. */
    private function seedPhaseTwo($employees, CarbonImmutable $today, string $tz): void
    {
        $results = [PresenceCheck::OK, PresenceCheck::OK, PresenceCheck::OK, PresenceCheck::OK, PresenceCheck::NO_RESPONSE, PresenceCheck::OUT_OF_RADIUS, PresenceCheck::SKIPPED];
        $fixed = $employees->filter(fn ($e) => $e->attendance_type === AttendanceType::Fixed)->values();
        foreach (Attendance::whereNotNull('check_in_at')->whereIn('employee_id', $fixed->pluck('id'))->get() as $a) {
            if (mt_rand(1, 100) > 60) {
                continue;
            }
            $at = CarbonImmutable::parse($a->date->toDateString().' '.sprintf('%02d:%02d', mt_rand(9, 14), mt_rand(0, 59)), $tz);
            if ($at->isFuture()) {
                continue;
            }
            $result = $results[mt_rand(0, count($results) - 1)];
            $office = Office::find($a->check_in_office_id);
            PresenceCheck::create([
                'institution_id' => $a->institution_id, 'employee_id' => $a->employee_id, 'attendance_id' => $a->id,
                'result' => $result, 'scheduled_at' => $at->utc(), 'sent_at' => $at->utc(), 'deadline_at' => $at->addMinutes(15)->utc(),
                'responded_at' => in_array($result, [PresenceCheck::OK, PresenceCheck::OUT_OF_RADIUS], true) ? $at->addMinutes(mt_rand(1, 12))->utc() : null,
                'office_id' => $office?->id, 'distance_m' => $result === PresenceCheck::OUT_OF_RADIUS ? mt_rand(400, 2500) : mt_rand(5, 80),
            ]);
        }

        $reasons = ['Mengantar berkas ke bank', 'Rapat koordinasi di rektorat', 'Mengambil dokumen di kantor pos', 'Keperluan keluarga mendesak'];
        foreach ($fixed->random(min(4, $fixed->count())) as $i => $e) {
            $out = $today->setTime(9 + $i, 15);
            if ($out->isFuture()) {
                $out = $today->subDay()->setTime(9 + $i, 15);
            }
            ExitPermit::create([
                'institution_id' => $e->institution_id, 'employee_id' => $e->id, 'reason' => $reasons[$i],
                'out_at' => $out->utc(), 'back_at' => $i === 0 ? null : $out->addMinutes(30 + 15 * $i)->utc(),
                'status' => $i < 2 ? LeaveStatus::Pending : LeaveStatus::Approved,
            ]);
        }

        foreach ($employees->random(3) as $i => $e) {
            AttendanceCorrection::create([
                'institution_id' => $e->institution_id, 'employee_id' => $e->id,
                'date' => $today->subDays(2 + $i)->toDateString(),
                'check_in_time' => $i === 1 ? null : '07:35', 'check_out_time' => '16:05',
                'reason' => ['HP mati saat jam pulang', 'Lupa absen pulang karena rapat sampai sore', 'Aplikasi gagal mengirim foto'][$i],
                'status' => LeaveStatus::Pending,
            ]);
        }
    }
}
