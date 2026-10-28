<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly PushNotifier $push,
    ) {}

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
