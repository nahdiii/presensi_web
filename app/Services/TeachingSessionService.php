<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\RejectReason;
use App\Exceptions\AttendanceRejected;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\TeachingSchedule;
use App\Models\TeachingSession;
use App\Support\PunchData;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Presensi per sesi mengajar (fase 3). Dosen absen sekali per sesi, dengan
 * lokasi dan selfie seperti absen harian, dalam jendela 15 menit sebelum
 * kuliah dimulai sampai kuliah selesai. Sesi yang lewat tanpa absen ditandai
 * tidak hadir oleh attendance:close-day.
 */
class TeachingSessionService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * Jadwal mengajar hari ini beserta keadaannya untuk beranda aplikasi.
     *
     * @return list<array{schedule: TeachingSchedule, session: ?TeachingSession, state: string, opens_at: string, message: ?string}>
     */
    public function forDay(Employee $employee, CarbonImmutable $now): array
    {
        $date = $now->toDateString();
        $tz = $now->getTimezone()->getName();
        $schedules = $employee->teachingSchedules()->occursOn($date)->with(['course', 'office'])->orderBy('start_time')->get();
        if ($schedules->isEmpty()) {
            return [];
        }
        $sessions = TeachingSession::where('employee_id', $employee->id)->whereDate('date', $date)
            ->get()->keyBy('teaching_schedule_id');
        [$offStatus, $offReason] = $this->offDay($employee, $date);

        return $schedules->map(function (TeachingSchedule $s) use ($sessions, $date, $tz, $now, $offStatus, $offReason) {
            $session = $sessions->get($s->id);
            $opens = $s->startAt($date, $tz)->subMinutes(config('attendance.sessions.open_before_min'));
            [$state, $message] = match (true) {
                (bool) $session?->checked_in_at => ['done', null],
                $session?->status === AttendanceStatus::Absent => ['missed', 'Tidak hadir.'],
                $session !== null => ['off', $session->status->getLabel()],
                $offStatus !== null => ['off', $offReason],
                $now->lt($opens) => ['upcoming', 'Absen dibuka pukul '.$opens->format('H:i').'.'],
                $now->lt($s->endAt($date, $tz)) => ['open', null],
                default => ['missed', 'Sesi sudah berakhir tanpa absen.'],
            };

            return ['schedule' => $s, 'session' => $session, 'state' => $state, 'opens_at' => $opens->format('H:i'), 'message' => $message];
        })->all();
    }

    public function checkIn(Employee $employee, TeachingSchedule $schedule, PunchData $p, ?string $topic = null): TeachingSession
    {
        $ctx = [];

        return $this->attendance->audited($employee, 'session', $p, $ctx, function () use ($employee, $schedule, $p, $topic, &$ctx) {
            $device = $this->attendance->assertDevice($employee, $p->deviceUid);
            $tz = $schedule->office?->timezone ?? $employee->offices->first()?->timezone ?? config('attendance.timezone');
            $now = CarbonImmutable::now($tz);
            $date = $now->toDateString();

            if ($schedule->employee_id !== $employee->id || ! TeachingSchedule::whereKey($schedule->id)->occursOn($date)->exists()) {
                throw new AttendanceRejected(RejectReason::OutsideWindow, 'Tidak ada jadwal '.$schedule->label.' hari ini.');
            }
            if ($holiday = $this->holiday($employee, $date)) {
                throw new AttendanceRejected(RejectReason::OutsideWindow, "Hari ini libur ($holiday->name).");
            }
            $existing = TeachingSession::where('teaching_schedule_id', $schedule->id)->whereDate('date', $date)->first();
            if ($existing?->checked_in_at) {
                throw new AttendanceRejected(RejectReason::AlreadyCheckedIn,
                    'Anda sudah absen sesi ini pukul '.$existing->checked_in_at->setTimezone($tz)->format('H:i').'.');
            }
            $start = $schedule->startAt($date, $tz);
            $opens = $start->subMinutes(config('attendance.sessions.open_before_min'));
            if ($now->lt($opens)) {
                throw new AttendanceRejected(RejectReason::OutsideWindow, 'Absen sesi baru dibuka pukul '.$opens->format('H:i').'.');
            }
            if ($now->gte($schedule->endAt($date, $tz))) {
                throw new AttendanceRejected(RejectReason::OutsideWindow,
                    'Sesi sudah berakhir pukul '.$schedule->endAt($date, $tz)->format('H:i').'.');
            }

            // Sesi dengan gedung tertentu hanya bisa diabsen di radius gedung itu.
            $allowed = $schedule->office ? collect([$schedule->office]) : null;
            try {
                [$office, $distance] = $this->attendance->assertLocation($employee, $p, $ctx, $allowed);
            } catch (AttendanceRejected $e) {
                if (! $schedule->office || ! in_array($e->reason, [RejectReason::OutOfRadius, RejectReason::OfficeNotAllowed], true)) {
                    throw $e;
                }
                $far = isset($ctx['distance_m']) ? ' Anda '.round($ctx['distance_m']).' m dari sana.' : '';
                throw new AttendanceRejected($e->reason, "Sesi ini dijadwalkan di {$schedule->office->name}.$far");
            }
            $this->attendance->assertFace($p);

            $late = $now->gt($start->addMinutes(config('attendance.sessions.late_after_min')))
                ? (int) $start->diffInMinutes($now) : 0;
            $photo = $this->attendance->storePhoto($employee, $p, $date, 'session');

            try {
                $session = DB::transaction(function () use ($employee, $schedule, $date, $now, $late, $office, $distance, $p, $photo, $topic, $device) {
                    $row = TeachingSession::where('teaching_schedule_id', $schedule->id)->whereDate('date', $date)->lockForUpdate()->first()
                        ?? new TeachingSession(['teaching_schedule_id' => $schedule->id, 'date' => $date]);
                    if ($row->checked_in_at) {
                        throw new AttendanceRejected(RejectReason::AlreadyCheckedIn, 'Anda sudah absen sesi ini.');
                    }
                    $row->fill($this->snapshot($schedule) + [
                        'institution_id' => $employee->institution_id,
                        'employee_id' => $employee->id,
                        'status' => $late ? AttendanceStatus::Late : AttendanceStatus::Present,
                        'late_minutes' => $late,
                        'checked_in_at' => $now->utc(),
                        'office_id' => $office->id,
                        'lat' => $p->lat,
                        'lng' => $p->lng,
                        'accuracy_m' => $p->accuracy,
                        'distance_m' => round($distance, 2),
                        'photo_path' => $photo,
                        'topic' => $topic,
                        'device_id' => $device->id,
                    ])->save();

                    return $row;
                });
            } catch (UniqueConstraintViolationException) {
                throw new AttendanceRejected(RejectReason::AlreadyCheckedIn, 'Anda sudah absen sesi ini.');
            }

            $this->attendance->queuePhoto($photo);

            return $session;
        });
    }

    /**
     * Menutup sesi yang sudah berakhir pada $date tanpa absen: tidak hadir,
     * atau izin/sakit/tugas luar/libur bila berlaku. Aman dijalankan berulang.
     *
     * @return array<string, int>
     */
    public function closeDay(string $date, CarbonImmutable $now): array
    {
        $counts = [];
        TeachingSchedule::occursOn($date)
            ->with(['course', 'office', 'employee.offices', 'employee.user'])
            ->whereHas('employee.user', fn ($query) => $query->where('is_active', true))
            ->whereDoesntHave('sessions', fn ($query) => $query->whereDate('date', $date))
            ->chunkById(200, function ($schedules) use ($date, $now, &$counts) {
                foreach ($schedules as $s) {
                    $e = $s->employee;
                    $tz = $s->office?->timezone ?? $e->offices->first()?->timezone ?? config('attendance.timezone');
                    if ($now->lt($s->endAt($date, $tz)) || $date < $e->created_at->setTimezone($tz)->toDateString()) {
                        continue;
                    }
                    [$status] = $this->offDay($e, $date);
                    $status ??= AttendanceStatus::Absent;
                    TeachingSession::create($this->snapshot($s) + [
                        'institution_id' => $e->institution_id,
                        'employee_id' => $e->id,
                        'teaching_schedule_id' => $s->id,
                        'date' => $date,
                        'status' => $status,
                    ]);
                    $counts["sesi {$status->value}"] = ($counts["sesi {$status->value}"] ?? 0) + 1;
                }
            });

        return $counts;
    }

    /**
     * Libur kampus atau izin yang disetujui pada tanggal itu.
     *
     * @return array{0: ?AttendanceStatus, 1: ?string}
     */
    private function offDay(Employee $employee, string $date): array
    {
        if ($holiday = $this->holiday($employee, $date)) {
            return [AttendanceStatus::Holiday, $holiday->name];
        }
        $leave = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->first();

        return $leave ? [$leave->type->attendanceStatus(), $leave->type->getLabel()] : [null, null];
    }

    private function holiday(Employee $employee, string $date): ?CalendarEvent
    {
        return CalendarEvent::where('institution_id', $employee->institution_id)->whereDate('date', $date)->first();
    }

    private function snapshot(TeachingSchedule $s): array
    {
        return [
            'course_code' => $s->course->code,
            'course_name' => $s->course->name,
            'class_name' => $s->class_name,
            'room' => $s->room,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
        ];
    }
}
