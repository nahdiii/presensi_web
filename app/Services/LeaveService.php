<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Pengajuan baru dari pegawai. Ditolak bila terlalu lama ke belakang,
     * bertumpuk dengan pengajuan lain yang masih berlaku, tidak berisi hari
     * kerja, atau (untuk cuti) melebihi sisa jatah tahun itu.
     *
     * @param  array{type: string, start_date: string, end_date: string, reason: string}  $data
     */
    public function submit(Employee $employee, array $data, ?string $attachmentPath = null): LeaveRequest
    {
        $type = LeaveType::from($data['type']);
        $start = CarbonImmutable::parse($data['start_date']);
        $end = CarbonImmutable::parse($data['end_date']);
        $today = CarbonImmutable::now(config('attendance.timezone'))->startOfDay();
        $backdate = config('attendance.leave.max_backdate_days');

        if ($start->lt($today->subDays($backdate))) {
            throw ValidationException::withMessages(['start_date' => "Tanggal mulai paling lama $backdate hari ke belakang."]);
        }
        $clash = $employee->leaveRequests()
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->first();
        if ($clash) {
            throw ValidationException::withMessages(['start_date' => "Bertumpuk dengan pengajuan {$clash->type->getLabel()} "
                .$this->range($clash).' ('.$clash->status->getLabel().').']);
        }
        $perYear = $this->workdaysPerYear($employee, $start, $end);
        $days = array_sum($perYear);
        if ($days === 0) {
            throw ValidationException::withMessages(['end_date' => 'Rentang tanggal ini tidak berisi hari kerja.']);
        }
        if ($type === LeaveType::Cuti) {
            foreach ($perYear as $year => $n) {
                $left = $this->quota($employee, $year)['remaining'];
                if ($n > $left) {
                    throw ValidationException::withMessages(['end_date' => "Sisa cuti tahun $year tinggal $left hari kerja; pengajuan ini $n hari."]);
                }
            }
        }

        return $employee->leaveRequests()->create([
            'institution_id' => $employee->institution_id,
            'type' => $type,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'reason' => $data['reason'],
            'attachment_path' => $attachmentPath,
            'status' => LeaveStatus::Pending,
        ]);
    }

    /**
     * Jatah cuti tahunan dalam hari kerja. Pengajuan yang masih menunggu ikut
     * mengurangi sisa supaya tidak bisa mengajukan melebihi jatah.
     *
     * @return array{year: int, quota: int, used: int, pending: int, remaining: int}
     */
    public function quota(Employee $employee, int $year): array
    {
        $quota = $employee->annual_leave_quota ?? config('attendance.leave.annual_quota_days');
        $used = $pending = 0;
        $leaves = $employee->leaveRequests()
            ->where('type', LeaveType::Cuti)
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('start_date', '<=', "$year-12-31")
            ->whereDate('end_date', '>=', "$year-01-01")
            ->get();
        foreach ($leaves as $leave) {
            $n = $leave->days !== null && $leave->start_date->year === $year && $leave->end_date->year === $year
                ? $leave->days
                : ($this->workdaysPerYear($employee, $leave->start_date->toImmutable(), $leave->end_date->toImmutable())[$year] ?? 0);
            $leave->status === LeaveStatus::Approved ? $used += $n : $pending += $n;
        }

        return ['year' => $year, 'quota' => $quota, 'used' => $used, 'pending' => $pending, 'remaining' => max(0, $quota - $used - $pending)];
    }

    /** @return array<int, int> hari kerja (bukan libur menurut jadwal/kalender) per tahun */
    private function workdaysPerYear(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $days[$day->year] ??= 0;
            if (! $this->shifts->resolve($employee, $day->toImmutable())->isDayOff) {
                $days[$day->year]++;
            }
        }

        return $days;
    }

    private function range(LeaveRequest $leave): string
    {
        return $leave->start_date->eq($leave->end_date)
            ? $leave->start_date->translatedFormat('d M Y')
            : $leave->start_date->translatedFormat('d M').' – '.$leave->end_date->translatedFormat('d M Y');
    }

    /**
     * Setujui izin dan tandai hari-hari kerja dalam rentangnya. Hari yang
     * sudah ada absen masuknya tidak ditimpa.
     */
    public function approve(LeaveRequest $leave, User $approver, ?string $note = null): void
    {
        DB::transaction(function () use ($leave, $approver, $note) {
            $leave->update([
                'status' => LeaveStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'review_note' => $note,
            ]);

            $employee = $leave->employee;
            foreach (CarbonPeriod::create($leave->start_date, $leave->end_date) as $day) {
                $schedule = $this->shifts->resolve($employee, $day->toImmutable());
                if ($schedule->isDayOff) {
                    continue;
                }
                $row = Attendance::firstOrNew(['employee_id' => $employee->id, 'date' => $day->toDateString()]);
                if ($row->check_in_at) {
                    continue;
                }
                $row->fill([
                    'institution_id' => $employee->institution_id,
                    'status' => $leave->type->attendanceStatus(),
                    'schedule_snapshot' => $schedule->toArray(),
                    'note' => $leave->type->getLabel().': '.str($leave->reason)->limit(150),
                ])->save();
            }
        });

        $this->notify($leave, true);
    }

    public function reject(LeaveRequest $leave, User $approver, ?string $note = null): void
    {
        $leave->update([
            'status' => LeaveStatus::Rejected,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'review_note' => $note,
        ]);
        $this->notify($leave, false);
    }

    private function notify(LeaveRequest $leave, bool $approved): void
    {
        $this->push->send($leave->employee, $leave->type->getLabel().' '.($approved ? 'disetujui' : 'ditolak'),
            $leave->start_date->translatedFormat('d M').' – '.$leave->end_date->translatedFormat('d M Y').($leave->review_note ? ': '.$leave->review_note : ''),
            ['type' => 'leave', 'id' => (string) $leave->id]);
    }
}
