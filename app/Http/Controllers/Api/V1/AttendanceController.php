<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PunchRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\ExitPermitResource;
use App\Http\Resources\PresenceCheckResource;
use App\Http\Resources\TeachingSlotResource;
use App\Models\Attendance;
use App\Services\AttendanceService;
use App\Services\ExitPermitService;
use App\Services\PresenceCheckService;
use App\Services\TeachingSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $service) {}

    /** Status hari ini: jadwal, presensi, dan aksi yang tersedia. */
    public function today(Request $request): JsonResponse
    {
        $employee = $request->user()->employee->load('offices');
        $t = $this->service->today($employee);
        $a = $t['attendance']?->load(['checkInOffice', 'checkOutOffice']);
        $s = $t['schedule'];
        $tz = $t['tz'];

        return response()->json(['data' => [
            'date' => $t['date'],
            'server_time' => $t['now']->toIso8601String(),
            'timezone' => $tz,
            'utc_offset_minutes' => $t['now']->utcOffset(),
            'schedule' => [
                'pattern_name' => $s->patternName,
                'type' => $s->type,
                'is_day_off' => $s->isDayOff,
                'start_time' => $s->startTime,
                'end_time' => $s->endTime,
                'checkin_opens_at' => $s->isDayOff ? null : $s->opensAt($t['date'], $tz)->format('H:i'),
                'late_after' => $s->isDayOff ? null : $s->lateAfter($t['date'], $tz)->format('H:i'),
                'min_daily_minutes' => $s->minDailyMinutes,
                'reason' => $s->reason,
            ],
            'attendance' => $a ? new AttendanceResource($a) : null,
            'next_action' => $t['action'],
            'next_action_message' => $t['message'],
            // Izin/cuti yang sedang berjalan hari ini; pegawai bisa membatalkannya bila ternyata masuk kerja.
            'leave' => $t['leave'] ? [
                'id' => $t['leave']->id,
                'type_label' => $t['leave']->typeLabel(),
                'range' => $t['leave']->rangeLabel(),
            ] : null,
            // Fase 2: izin keluar hanya untuk presensi tetap yang sedang di kampus.
            'can_exit' => $t['action'] === 'check_out' && $employee->attendance_type->effective() === AttendanceType::Fixed,
            'exit_permit' => ($permit = app(ExitPermitService::class)->active($employee)) ? new ExitPermitResource($permit) : null,
            'presence_check' => ($check = app(PresenceCheckService::class)->pendingFor($employee)) ? new PresenceCheckResource($check) : null,
            // Fase 3: jadwal mengajar hari ini (kosong bila tidak mengajar atau fiturnya nonaktif).
            'teaching' => config('attendance.sessions.enabled')
                ? TeachingSlotResource::collection(app(TeachingSessionService::class)->forDay($employee, $t['now']))
                : [],
        ]]);
    }

    /** Absen masuk. Jam diambil dari server; jam di HP diabaikan. */
    public function checkIn(PunchRequest $request): JsonResponse
    {
        $a = $this->service->checkIn($request->user()->employee, $request->toPunch());
        $message = $a->status === AttendanceStatus::Late
            ? "Anda terlambat {$a->late_minutes} menit."
            : 'Selamat bekerja!';

        return (new AttendanceResource($a->load('checkInOffice')))
            ->additional(['meta' => ['message' => $message]])
            ->response()->setStatusCode(201);
    }

    /** Absen pulang. */
    public function checkOut(PunchRequest $request): JsonResponse
    {
        $a = $this->service->checkOut($request->user()->employee, $request->toPunch());
        $h = intdiv($a->worked_minutes, 60);
        $m = $a->worked_minutes % 60;
        $message = "Total hadir {$h} jam {$m} menit.".($a->is_early_leave ? ' Tercatat pulang lebih awal.' : '');

        return (new AttendanceResource($a->load(['checkInOffice', 'checkOutOffice'])))
            ->additional(['meta' => ['message' => $message]])
            ->response();
    }

    /**
     * Riwayat satu bulan (?month=2026-10) atau rentang tanggal
     * (?from=2026-09-25&to=2026-10-24), untuk kampus yang periodenya tidak
     * mulai dari awal bulan. Rentang maksimal satu tahun.
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.required_with' => 'Tanggal awal wajib diisi.',
            'to.required_with' => 'Tanggal akhir wajib diisi.',
            'to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);

        $month = null;
        if ($request->filled('from')) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $request->input('from'));
            $to = CarbonImmutable::createFromFormat('Y-m-d', $request->input('to'));
            if ($from->addYear()->lt($to)) {
                throw ValidationException::withMessages(['to' => 'Rentang tanggal maksimal satu tahun.']);
            }
        } else {
            $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->input('month') ?? now()->format('Y-m')).'-01');
            $from = $month->startOfMonth();
            $to = $month->endOfMonth();
        }

        $items = $request->user()->employee->attendances()
            ->with(['checkInOffice', 'checkOutOffice', 'leaveRequest'])
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderByDesc('date')
            ->get();

        $summary = collect(AttendanceStatus::cases())
            ->mapWithKeys(fn (AttendanceStatus $s) => [$s->value => $items->where('status', $s)->count()])
            ->put('worked_minutes', (int) $items->sum('worked_minutes'))
            ->put('late_minutes', (int) $items->sum('late_minutes'))
            ->put('early_leave_minutes', (int) $items->sum('early_leave_minutes'))
            ->put('deduction_amount', (int) $items->sum('deduction_amount'));

        return response()->json(['data' => [
            'month' => $month?->format('Y-m'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $summary,
            'items' => AttendanceResource::collection($items),
        ]]);
    }

    /** Detail satu presensi beserta foto (URL bertanda tangan yang kedaluwarsa). */
    public function show(Attendance $attendance): AttendanceResource
    {
        Gate::authorize('view', $attendance);
        $resource = new AttendanceResource($attendance->load(['checkInOffice', 'checkOutOffice', 'leaveRequest']));
        $resource->withPhotos = true;

        return $resource;
    }

    /**
     * Jam server untuk ditampilkan di aplikasi.
     *
     * @unauthenticated
     */
    public function serverTime(): JsonResponse
    {
        $now = CarbonImmutable::now(config('attendance.timezone'));

        return response()->json(['data' => [
            'server_time' => $now->toIso8601String(),
            'timezone' => $now->getTimezone()->getName(),
            'utc_offset_minutes' => $now->utcOffset(),
        ]]);
    }
}
