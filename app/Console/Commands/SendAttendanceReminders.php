<?php

namespace App\Console\Commands;

use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\TeachingSchedule;
use App\Notifications\Push\PushNotifier;
use App\Services\ShiftResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Pengingat absen masuk menjelang jam mulai dan absen pulang saat jam kerja
 * selesai, serta absen sesi menjelang kuliah. Tiap pengingat dikirim sekali.
 */
#[Signature('attendance:remind')]
#[Description('Kirim pengingat absen masuk dan pulang')]
class SendAttendanceReminders extends Command
{
    public function handle(ShiftResolver $shifts, PushNotifier $push): int
    {
        $cfg = config('attendance.reminders');
        $sent = 0;

        Employee::with(['offices', 'activeDevice'])
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->whereHas('activeDevice', fn ($q) => $q->whereNotNull('fcm_token'))
            ->where('attendance_type', AttendanceType::Fixed)
            ->each(function (Employee $e) use ($shifts, $push, $cfg, &$sent) {
                $tz = $e->offices->first()?->timezone ?? config('attendance.timezone');
                $now = CarbonImmutable::now($tz);
                $date = $now->toDateString();
                $s = $shifts->resolve($e, $date);
                if ($s->isDayOff) {
                    return;
                }
                $a = Attendance::where('employee_id', $e->id)->whereDate('date', $date)->first();

                $inAt = $s->startAt($date, $tz)->subMinutes($cfg['check_in_before_min']);
                if (! $a?->check_in_at && $now->between($inAt, $s->startAt($date, $tz))
                    && Cache::add("remind:in:{$e->id}:$date", 1, now()->addDay())) {
                    $push->send($e, 'Jangan lupa absen masuk', 'Jam kerja mulai pukul '.$s->startTime.'.', ['type' => 'reminder']);
                    $sent++;
                }

                $outAt = $s->endAt($date, $tz)->addMinutes($cfg['check_out_after_min']);
                if ($a?->check_in_at && ! $a->check_out_at && $now->between($outAt, $outAt->addMinutes(30))
                    && Cache::add("remind:out:{$e->id}:$date", 1, now()->addDay())) {
                    $push->send($e, 'Jangan lupa absen pulang', 'Absen pulang di lokasi kampus supaya hari ini tidak tercatat "tidak absen pulang".', ['type' => 'reminder']);
                    $sent++;
                }
            });

        // Fase 3: pengingat absen sesi saat jendela absen kuliah dibuka.
        $now = CarbonImmutable::now(config('attendance.timezone'));
        $date = $now->toDateString();
        TeachingSchedule::occursOn($date)
            ->with(['course', 'office', 'employee.offices', 'employee.activeDevice'])
            ->whereHas('employee.user', fn ($q) => $q->where('is_active', true))
            ->whereHas('employee.activeDevice', fn ($q) => $q->whereNotNull('fcm_token'))
            ->whereDoesntHave('sessions', fn ($q) => $q->whereDate('date', $date))
            ->each(function (TeachingSchedule $s) use ($push, $date, &$sent) {
                $tz = $s->office?->timezone ?? $s->employee->offices->first()?->timezone ?? config('attendance.timezone');
                $start = $s->startAt($date, $tz);
                $at = $start->subMinutes(config('attendance.sessions.reminder_before_min'));
                if (CarbonImmutable::now($tz)->between($at, $start) && Cache::add("remind:session:{$s->id}:$date", 1, now()->addDay())) {
                    $push->send($s->employee, 'Kuliah '.$s->label.' pukul '.$start->format('H:i'),
                        'Absen sesi'.($s->room ? " di {$s->room}" : '').' sebelum kuliah dimulai.',
                        ['type' => 'session', 'id' => (string) $s->id]);
                    $sent++;
                }
            });

        if ($sent) {
            $this->info("$sent pengingat dikirim.");
        }

        return self::SUCCESS;
    }
}
