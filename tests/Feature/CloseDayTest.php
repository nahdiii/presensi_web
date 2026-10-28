<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCampus;
use Tests\TestCase;

class CloseDayTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attendance');
        Queue::fake();
        $this->buildCampus();
    }

    public function test_nightly_job_marks_absent_incomplete_and_leave(): void
    {
        $absent = $this->makeEmployee();
        $noCheckout = $this->makeEmployee();
        $complete = $this->makeEmployee();
        $onLeave = $this->makeEmployee();
        LeaveRequest::create([
            'institution_id' => $this->inst->id, 'employee_id' => $onLeave->id, 'type' => LeaveType::Sakit,
            'start_date' => '2026-10-28', 'end_date' => '2026-10-28', 'reason' => 'Demam', 'status' => LeaveStatus::Approved,
        ]);

        $this->at('2026-10-28 07:20');
        $this->punch($noCheckout)->assertCreated();
        $this->punch($complete)->assertCreated();
        $this->at('2026-10-28 16:05');
        $this->punch($complete, 'out')->assertOk();

        $this->at('2026-10-28 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();

        $status = fn ($e) => Attendance::where('employee_id', $e->id)->sole()->status;
        $this->assertSame(AttendanceStatus::Absent, $status($absent));
        $this->assertSame(AttendanceStatus::Incomplete, $status($noCheckout));
        $this->assertSame(AttendanceStatus::Present, $status($complete));
        $this->assertSame(AttendanceStatus::Sick, $status($onLeave));

        // Aman dijalankan ulang.
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame(4, Attendance::count());

        // Sabtu–Minggu tidak dicatat (Kamis & Jumat dilewati di sini: hanya 2 hari terakhir yang diproses).
        $this->at('2026-11-01 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame(4, Attendance::count());
    }

    public function test_job_waits_until_the_work_day_is_over(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 12:00');
        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame(0, Attendance::where('employee_id', $e->id)->count());
    }

    public function test_approving_leave_marks_scheduled_days(): void
    {
        $e = $this->makeEmployee();
        $approver = User::create(['name' => 'Kepala', 'email' => 'kepala@kampus.test', 'password' => 'x']);
        $leave = LeaveRequest::create([
            'institution_id' => $this->inst->id, 'employee_id' => $e->id, 'type' => LeaveType::TugasLuar,
            'start_date' => '2026-10-29', 'end_date' => '2026-11-02', 'reason' => 'Seminar nasional', 'status' => LeaveStatus::Pending,
        ]);

        app(LeaveService::class)->approve($leave, $approver);

        // Kamis, Jumat, Senin; Sabtu–Minggu dilewati.
        $this->assertSame(['2026-10-29', '2026-10-30', '2026-11-02'],
            Attendance::orderBy('date')->get()->map(fn ($a) => $a->date->toDateString())->all());
        $this->assertSame(AttendanceStatus::Duty, Attendance::first()->status);
        $this->assertSame(LeaveStatus::Approved, $leave->fresh()->status);
    }
}
