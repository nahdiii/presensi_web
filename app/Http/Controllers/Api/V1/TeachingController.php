<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SessionPunchRequest;
use App\Http\Resources\TeachingSessionResource;
use App\Http\Resources\TeachingSlotResource;
use App\Models\TeachingSchedule;
use App\Services\TeachingSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Presensi per sesi mengajar (fase 3). */
class TeachingController extends Controller
{
    public function __construct(private readonly TeachingSessionService $service) {}

    /** Jadwal mengajar hari ini beserta keadaannya (juga ikut di attendance/today). */
    public function today(Request $request): JsonResponse
    {
        $employee = $request->user()->employee->load('offices');
        $tz = $employee->offices->first()?->timezone ?? config('attendance.timezone');

        return response()->json(['data' => TeachingSlotResource::collection($this->service->forDay($employee, CarbonImmutable::now($tz)))]);
    }

    /** Absen satu sesi: lokasi + selfie, dan pokok bahasan (opsional). Jam dari server. */
    public function checkIn(SessionPunchRequest $request, TeachingSchedule $teachingSchedule): JsonResponse
    {
        abort_unless($teachingSchedule->employee_id === $request->user()->employee->id, 403);
        $session = $this->service->checkIn($request->user()->employee, $teachingSchedule->load(['course', 'office']),
            $request->toPunch(), $request->input('topic'));
        $message = $session->status === AttendanceStatus::Late
            ? "Sesi tercatat, terlambat {$session->late_minutes} menit."
            : 'Selamat mengajar!';

        return (new TeachingSessionResource($session->load('office')))
            ->additional(['meta' => ['message' => $message]])
            ->response()->setStatusCode(201);
    }

    /** Riwayat sesi satu bulan, mis. ?month=2026-10. */
    public function history(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->input('month') ?? now()->format('Y-m')).'-01');
        $items = $request->user()->employee->teachingSessions()->with('office')
            ->whereDate('date', '>=', $month->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $month->endOfMonth()->toDateString())
            ->orderByDesc('date')->orderByDesc('start_time')
            ->get();
        $held = $items->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late]);

        return response()->json(['data' => [
            'month' => $month->format('Y-m'),
            'summary' => [
                'scheduled' => $items->where('status', '!=', AttendanceStatus::Holiday)->count(),
                'held' => $held->count(),
                'late' => $items->where('status', AttendanceStatus::Late)->count(),
                'absent' => $items->where('status', AttendanceStatus::Absent)->count(),
                'excused' => $items->whereIn('status', [AttendanceStatus::Leave, AttendanceStatus::Sick, AttendanceStatus::Duty])->count(),
            ],
            'items' => TeachingSessionResource::collection($items),
        ]]);
    }
}
