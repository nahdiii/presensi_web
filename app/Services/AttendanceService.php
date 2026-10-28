<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\RejectReason;
use App\Exceptions\AttendanceRejected;
use App\Jobs\ProcessAttendancePhoto;
use App\Models\Attendance;
use App\Models\AttendanceAttempt;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Office;
use App\Support\DaySchedule;
use App\Support\PunchData;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Satu-satunya tempat aturan absen. Dipanggil oleh API (Flutter) maupun
 * panel admin. Setiap percobaan, diterima atau ditolak, dicatat di
 * attendance_attempts sebagai jejak audit.
 */
class AttendanceService
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly GeofenceService $geofence,
    ) {}

    public function checkIn(Employee $employee, PunchData $p): Attendance
    {
        $ctx = [];

        return $this->audited($employee, 'in', $p, $ctx, function () use ($employee, $p, &$ctx) {
            $device = $this->assertDevice($employee, $p->deviceUid);
            [$office, $distance] = $this->assertLocation($employee, $p, $ctx);
            $now = CarbonImmutable::now($office->timezone);
            [$date, $schedule] = $this->checkInDay($employee, $now);
            $tz = $office->timezone;

            $existing = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
            if ($existing?->check_in_at) {
                throw new AttendanceRejected(RejectReason::AlreadyCheckedIn,
                    'Anda sudah absen masuk pukul '.$existing->check_in_at->setTimezone($tz)->format('H:i').'.');
            }

            if ($schedule->isDayOff) {
                throw new AttendanceRejected(RejectReason::OutsideWindow,
                    'Hari ini tidak ada jadwal kerja'.($schedule->reason ? " ({$schedule->reason})" : '').'.');
            }
            if ($now->lt($schedule->opensAt($date, $tz))) {
                throw new AttendanceRejected(RejectReason::OutsideWindow,
                    'Absen masuk baru dibuka pukul '.$schedule->opensAt($date, $tz)->format('H:i').'.');
            }
            if ($now->gte($schedule->endAt($date, $tz))) {
                throw new AttendanceRejected(RejectReason::OutsideWindow,
                    'Jam kerja hari ini sudah berakhir pukul '.$schedule->endAt($date, $tz)->format('H:i').'.');
            }

            $this->assertFace($p);

            [$status, $late] = $this->resolveStatus($schedule, $date, $tz, $now);
            $photo = $this->storePhoto($employee, $p, $date, 'in');

            try {
                $attendance = DB::transaction(function () use ($employee, $date, $schedule, $status, $late, $now, $office, $distance, $p, $photo, $device) {
                    $row = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->lockForUpdate()->first()
                        ?? new Attendance(['employee_id' => $employee->id, 'date' => $date]);
                    if ($row->check_in_at) {
                        throw new AttendanceRejected(RejectReason::AlreadyCheckedIn, 'Anda sudah absen masuk hari ini.');
                    }
                    $row->fill([
                        'institution_id' => $employee->institution_id,
                        'status' => $status,
                        'schedule_snapshot' => $schedule->toArray(),
                        'late_minutes' => $late,
                        'check_in_office_id' => $office->id,
                        'check_in_at' => $now->utc(),
                        'check_in_lat' => $p->lat,
                        'check_in_lng' => $p->lng,
                        'check_in_accuracy_m' => $p->accuracy,
                        'check_in_distance_m' => round($distance, 2),
                        'check_in_photo_path' => $photo,
                        'device_id' => $device->id,
                    ])->save();

                    return $row;
                });
            } catch (UniqueConstraintViolationException) {
                // Dua tap bersamaan: request kedua kalah di indeks unik.
                throw new AttendanceRejected(RejectReason::AlreadyCheckedIn, 'Anda sudah absen masuk hari ini.');
            }

            $this->queuePhoto($photo);

            return $attendance->refresh();
        });
    }

    public function checkOut(Employee $employee, PunchData $p): Attendance
    {
        $ctx = [];

        return $this->audited($employee, 'out', $p, $ctx, function () use ($employee, $p, &$ctx) {
            $this->assertDevice($employee, $p->deviceUid);
            [$office, $distance] = $this->assertLocation($employee, $p, $ctx);
            $now = CarbonImmutable::now($office->timezone);
            $tz = $office->timezone;

            $attendance = $this->openAttendance($employee, $now);
            if (! $attendance) {
                $today = Attendance::where('employee_id', $employee->id)->whereDate('date', $now->toDateString())->first();
                if ($today?->check_out_at) {
                    throw new AttendanceRejected(RejectReason::AlreadyCheckedOut,
                        'Anda sudah absen pulang pukul '.$today->check_out_at->setTimezone($tz)->format('H:i').'.');
                }
                throw new AttendanceRejected(RejectReason::NotCheckedIn, 'Anda belum absen masuk hari ini.');
            }

            $date = $attendance->date->toDateString();
            $schedule = DaySchedule::fromArray($attendance->schedule_snapshot);
            $deadline = $schedule->endAt($date, $tz)->addMinutes(config('attendance.checkout_grace_min'));
            if ($now->gt($deadline)) {
                throw new AttendanceRejected(RejectReason::OutsideWindow,
                    'Batas absen pulang sudah lewat ('.$deadline->format('d/m H:i').').');
            }

            $this->assertFace($p);

            $photo = $this->storePhoto($employee, $p, $date, 'out');
            $checkIn = CarbonImmutable::instance($attendance->check_in_at)->setTimezone($tz);
            $worked = (int) $checkIn->diffInMinutes($now) - $schedule->breakOverlapMinutes($date, $tz, $checkIn, $now);
            $early = $schedule->isFlexible()
                ? $worked < ($schedule->minDailyMinutes ?? 0)
                : $now->lt($schedule->endAt($date, $tz));

            DB::transaction(function () use ($attendance, $office, $now, $p, $distance, $photo, $worked, $early) {
                $row = Attendance::whereKey($attendance->id)->lockForUpdate()->first();
                if ($row->check_out_at) {
                    throw new AttendanceRejected(RejectReason::AlreadyCheckedOut, 'Anda sudah absen pulang hari ini.');
                }
                $row->update([
                    'check_out_office_id' => $office->id,
                    'check_out_at' => $now->utc(),
                    'check_out_lat' => $p->lat,
                    'check_out_lng' => $p->lng,
                    'check_out_accuracy_m' => $p->accuracy,
                    'check_out_distance_m' => round($distance, 2),
                    'check_out_photo_path' => $photo,
                    'worked_minutes' => max(0, $worked),
                    'is_early_leave' => $early,
                ]);
            });

            $this->queuePhoto($photo);

            return $attendance->refresh();
        });
    }

    /**
     * Ringkasan hari ini untuk beranda aplikasi: jadwal, presensi, dan aksi
     * yang boleh dilakukan sekarang.
     */
    public function today(Employee $employee): array
    {
        $tz = $employee->offices->first()?->timezone ?? config('attendance.timezone');
        $now = CarbonImmutable::now($tz);

        $attendance = $this->openAttendance($employee, $now);
        if ($attendance) {
            $date = $attendance->date->toDateString();
            $schedule = DaySchedule::fromArray($attendance->schedule_snapshot);
        } else {
            [$date, $schedule] = $this->checkInDay($employee, $now);
            $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
        }

        [$action, $message] = $this->nextAction($attendance, $schedule, $date, $tz, $now);

        return compact('date', 'now', 'tz', 'schedule', 'attendance', 'action', 'message');
    }

    /** Status masuk dan menit terlambat, dihitung dari jam mulai shift. */
    public function resolveStatus(DaySchedule $schedule, string $date, string $tz, CarbonImmutable $now): array
    {
        if ($schedule->isFlexible() || $now->lte($schedule->lateAfter($date, $tz))) {
            return [AttendanceStatus::Present, 0];
        }

        return [AttendanceStatus::Late, (int) $schedule->startAt($date, $tz)->diffInMinutes($now)];
    }

    private function nextAction(?Attendance $a, DaySchedule $s, string $date, string $tz, CarbonImmutable $now): array
    {
        if ($a?->check_in_at && $a->check_out_at) {
            return ['done', 'Terima kasih, presensi hari ini lengkap.'];
        }
        if ($a?->check_in_at) {
            $deadline = $s->endAt($date, $tz)->addMinutes(config('attendance.checkout_grace_min'));
            if ($now->gt($deadline)) {
                return ['closed', 'Batas absen pulang sudah lewat.'];
            }
            $end = $s->endAt($date, $tz);

            return ['check_out', $s->isFlexible()
                ? 'Minimal hadir '.intdiv($s->minDailyMinutes ?? 0, 60).' jam per hari.'
                : ($now->lt($end) ? 'Jam pulang pukul '.$end->format('H:i').'.' : null)];
        }
        if ($s->isDayOff) {
            return ['day_off', $s->reason ?? 'Hari ini libur.'];
        }
        if ($now->lt($s->opensAt($date, $tz))) {
            return ['closed', 'Absen masuk dibuka pukul '.$s->opensAt($date, $tz)->format('H:i').'.'];
        }
        if ($now->gte($s->endAt($date, $tz))) {
            return ['closed', 'Jam kerja hari ini sudah berakhir.'];
        }

        return ['check_in', null];
    }

    /**
     * Tanggal kerja untuk absen masuk. Biasanya hari ini, kecuali shift
     * kemarin melewati tengah malam dan masih berjalan (mis. satpam malam
     * yang absen pukul 00.10 tercatat di tanggal shift dimulai).
     *
     * @return array{0: string, 1: DaySchedule}
     */
    private function checkInDay(Employee $employee, CarbonImmutable $now): array
    {
        $tz = $now->getTimezone()->getName();
        $yesterday = $now->subDay()->toDateString();
        $prev = $this->shifts->resolve($employee, $yesterday);
        if ($prev->crossesMidnight() && $now->lt($prev->endAt($yesterday, $tz))) {
            $taken = Attendance::where('employee_id', $employee->id)->whereDate('date', $yesterday)
                ->whereNotNull('check_in_at')->exists();
            if (! $taken) {
                return [$yesterday, $prev];
            }
        }
        $today = $now->toDateString();

        return [$today, $this->shifts->resolve($employee, $today)];
    }

    /** Presensi yang sudah absen masuk tetapi belum pulang (hari ini atau shift kemarin). */
    private function openAttendance(Employee $employee, CarbonImmutable $now): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('date', $now->toDateString())->orWhereDate('date', $now->subDay()->toDateString()))
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->orderByDesc('date')
            ->get()
            ->first(function (Attendance $a) use ($now) {
                if ($a->date->toDateString() === $now->toDateString()) {
                    return true;
                }
                // Shift kemarin hanya dianggap masih terbuka bila melewati tengah malam.
                return DaySchedule::fromArray($a->schedule_snapshot)->crossesMidnight();
            });
    }

    private function assertDevice(Employee $employee, ?string $uid): Device
    {
        if (! $employee->user->is_active) {
            throw new AttendanceRejected(RejectReason::InactiveAccount, 'Akun Anda dinonaktifkan. Hubungi Bagian SDM.');
        }
        $device = $employee->activeDevice;
        if (! $uid || ! $device || ! hash_equals($device->device_uid, $uid)) {
            throw new AttendanceRejected(RejectReason::UnknownDevice,
                'Perangkat ini tidak terdaftar untuk akun Anda. Minta Bagian SDM melakukan Reset Perangkat.');
        }

        return $device;
    }

    /**
     * Cek lokasi asli lalu radius. Jarak dicek terhadap lokasi terdekat yang
     * diizinkan; bila pegawai berada di radius lokasi kampus lain yang tidak
     * diizinkan untuknya, ditolak office_not_allowed.
     *
     * @return array{0: Office, 1: float}
     */
    private function assertLocation(Employee $employee, PunchData $p, array &$ctx): array
    {
        if ($p->isMocked) {
            throw new AttendanceRejected(RejectReason::MockLocation,
                'Lokasi palsu (Fake GPS) terdeteksi. Matikan aplikasi pengubah lokasi lalu coba lagi.');
        }
        if ($p->isRooted) {
            throw new AttendanceRejected(RejectReason::RootedDevice,
                'Perangkat terdeteksi di-root/jailbreak. Absen tidak dapat dilakukan dari perangkat ini.');
        }
        $maxAccuracy = config('attendance.max_accuracy_m');
        if ($p->accuracy > $maxAccuracy) {
            throw new AttendanceRejected(RejectReason::LowAccuracy,
                'Sinyal GPS kurang akurat (±'.round($p->accuracy)." m, batas $maxAccuracy m). Pindah ke tempat terbuka lalu coba lagi.");
        }

        $allowed = $employee->offices->where('is_active', true);
        $nearest = $this->geofence->nearest($allowed, $p->lat, $p->lng);
        if ($nearest) {
            [$office, $distance] = $nearest;
            $ctx = ['office_id' => $office->id, 'distance_m' => round($distance, 2)];
            if ($distance <= $office->radius_m) {
                return $nearest;
            }
        }

        $other = $this->geofence->nearest(
            Office::where('institution_id', $employee->institution_id)->where('is_active', true)
                ->whereNotIn('id', $allowed->pluck('id'))->get(),
            $p->lat, $p->lng,
        );
        if ($other && $other[1] <= $other[0]->radius_m) {
            throw new AttendanceRejected(RejectReason::OfficeNotAllowed,
                "Anda berada di {$other[0]->name}, yang bukan lokasi absen Anda.");
        }
        if (! $nearest) {
            throw new AttendanceRejected(RejectReason::OfficeNotAllowed, 'Anda belum didaftarkan di lokasi kampus mana pun.');
        }

        throw new AttendanceRejected(RejectReason::OutOfRadius,
            'Anda berada '.round($nearest[1])." m dari {$nearest[0]->name}. Batas {$nearest[0]->radius_m} m.");
    }

    private function assertFace(PunchData $p): void
    {
        if (! $p->photo) {
            throw new AttendanceRejected(RejectReason::NoFace, 'Foto selfie wajib dilampirkan.');
        }
        if ($p->faceCount !== 1) {
            throw new AttendanceRejected(RejectReason::NoFace, $p->faceCount === 0
                ? 'Wajah tidak terdeteksi di foto. Ulangi selfie dengan wajah terlihat jelas.'
                : 'Foto harus berisi satu wajah saja.');
        }
    }

    private function storePhoto(Employee $employee, PunchData $p, string $date, string $kind): string
    {
        $dir = 'photos/'.substr($date, 0, 7).'/'.$employee->id;
        $name = "$date-$kind-".Str::random(8).'.jpg';

        return $p->photo->storeAs($dir, $name, config('attendance.photo_disk'));
    }

    private function queuePhoto(string $path): void
    {
        ProcessAttendancePhoto::dispatch(config('attendance.photo_disk'), $path)->afterCommit();
    }

    /** Menjalankan $fn dan mencatat percobaannya, diterima maupun ditolak. */
    private function audited(Employee $employee, string $type, PunchData $p, array &$ctx, callable $fn): Attendance
    {
        // $ctx diisi selama cek berjalan (lokasi terdekat, jarak), jadi dibaca saat dicatat.
        $log = function (string $result, ?RejectReason $reason) use ($employee, $type, $p, &$ctx) {
            AttendanceAttempt::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'result' => $result,
                'reason' => $reason,
                'office_id' => $ctx['office_id'] ?? null,
                'distance_m' => $ctx['distance_m'] ?? null,
                'lat' => $p->lat,
                'lng' => $p->lng,
                'accuracy_m' => $p->accuracy,
                'is_mocked' => $p->isMocked,
                'is_rooted' => $p->isRooted,
                'device_uid' => $p->deviceUid,
                'ip' => $p->ip,
                'created_at' => now(),
            ]);
        };

        try {
            $attendance = $fn();
        } catch (AttendanceRejected $e) {
            $log('rejected', $e->reason);
            throw $e;
        }
        $log('accepted', null);

        return $attendance;
    }
}
