<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\WorkPatternType;
use App\Models\Attendance;
use App\Models\AttendanceAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsCampus;
use Tests\TestCase;

/** Semua skenario terima/tolak absen dari blueprint. */
class CheckInTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attendance');
        Queue::fake();
        $this->buildCampus();
    }

    public function test_check_in_inside_radius_and_window_is_present(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20'); // Rabu

        $this->punch($e)->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.late_minutes', 0)
            ->assertJsonPath('data.check_in.at', '2026-10-28T07:20:00+08:00');

        $a = Attendance::sole();
        $this->assertSame('2026-10-28', $a->date->toDateString());
        $this->assertEqualsWithDelta(22, $a->check_in_distance_m, 2);
        $this->assertSame('07:30', $a->schedule_snapshot['start_time']);
        Storage::disk('attendance')->assertExists($a->check_in_photo_path);
        $this->assertSame('accepted', AttendanceAttempt::sole()->result);
    }

    public function test_check_in_after_tolerance_is_late_with_minutes_from_shift_start(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:45'); // tepat di batas toleransi: masih tepat waktu
        $this->punch($e)->assertJsonPath('data.status', 'present');

        $e2 = $this->makeEmployee();
        $this->at('2026-10-28 07:50:30');
        $this->punch($e2)->assertCreated()
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.late_minutes', 20)
            ->assertJsonPath('meta.message', 'Anda terlambat 20 menit.');
    }

    public function test_out_of_radius_is_rejected_and_audited(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');

        $this->punch($e, 'in', ['latitude' => self::LAT + 0.003]) // ±334 m
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'out_of_radius')
            ->assertJsonPath('error.message', 'Anda berada 334 m dari Kampus 1. Batas 100 m.');

        $this->assertSame(0, Attendance::count());
        $attempt = AttendanceAttempt::sole();
        $this->assertSame('rejected', $attempt->result);
        $this->assertSame('out_of_radius', $attempt->reason->value);
        $this->assertEqualsWithDelta(334, (float) $attempt->distance_m, 2);
        $this->assertSame($this->kampus1->id, $attempt->office_id);
    }

    public function test_mocked_location_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');

        $this->punch($e, 'in', ['is_mocked' => 1])->assertStatus(422)->assertJsonPath('error.code', 'mock_location');
        $this->assertTrue(AttendanceAttempt::sole()->is_mocked);
    }

    public function test_rooted_device_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');

        $this->punch($e, 'in', ['is_rooted' => 1])->assertStatus(422)->assertJsonPath('error.code', 'rooted_device');
    }

    public function test_low_gps_accuracy_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');

        $this->punch($e, 'in', ['accuracy' => 80])->assertStatus(422)->assertJsonPath('error.code', 'low_accuracy');
    }

    public function test_unbound_device_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');

        $this->punch($e, 'in', [], deviceUid: 'perangkat-teman-1234567890')
            ->assertStatus(403)->assertJsonPath('error.code', 'unknown_device');
        $this->assertSame('unknown_device', AttendanceAttempt::sole()->reason->value);
        $this->assertSame(0, Attendance::count());
    }

    public function test_second_check_in_same_day_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($e)->assertCreated();

        $this->at('2026-10-28 08:05');
        $this->punch($e)->assertStatus(422)
            ->assertJsonPath('error.code', 'already_checked_in')
            ->assertJsonPath('error.message', 'Anda sudah absen masuk pukul 07:20.');
        $this->assertSame(1, Attendance::count());
    }

    public function test_night_shift_crossing_midnight_is_recorded_on_shift_start_date(): void
    {
        $night = $this->makePattern('Satpam Malam', WorkPatternType::Fixed, array_fill(1, 7, ['22:00', '06:00']), tolerance: 10, open: 30);
        $e = $this->makeEmployee(['work_pattern_id' => $night->id]);
        $late = $this->makeEmployee(['work_pattern_id' => $night->id]);

        $this->at('2026-10-28 21:50');
        $this->punch($e)->assertCreated()->assertJsonPath('data.date', '2026-10-28');

        // Satpam yang datang lewat tengah malam tetap masuk shift tanggal 28, terlambat.
        $this->at('2026-10-29 00:10');
        $this->punch($late)->assertCreated()
            ->assertJsonPath('data.date', '2026-10-28')
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.late_minutes', 130);

        $this->at('2026-10-29 06:05');
        $this->punch($e, 'out')->assertOk()
            ->assertJsonPath('data.date', '2026-10-28')
            ->assertJsonPath('data.worked_minutes', 495)
            ->assertJsonPath('data.is_early_leave', false);
        $this->assertSame(2, Attendance::count());
    }

    public function test_time_from_request_is_ignored(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 08:00');

        $this->punch($e, 'in', ['check_in_at' => '2026-10-28 07:00:00', 'time' => '07:00', 'timestamp' => 1793142000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.late_minutes', 30)
            ->assertJsonPath('data.check_in.at', '2026-10-28T08:00:00+08:00');
    }

    public function test_employee_cannot_view_another_employees_attendance(): void
    {
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($a)->assertCreated();
        $attendance = Attendance::sole();

        Sanctum::actingAs($b->user);
        $this->withHeader('X-Device-UID', $this->deviceUids[$b->id])
            ->getJson("/api/v1/attendance/{$attendance->id}")
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        Sanctum::actingAs($a->user);
        $this->withHeader('X-Device-UID', $this->deviceUids[$a->id])
            ->getJson("/api/v1/attendance/{$attendance->id}")
            ->assertOk()->assertJsonPath('data.id', $attendance->id);
    }

    public function test_check_in_before_window_opens_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 06:15');

        $this->punch($e)->assertStatus(422)
            ->assertJsonPath('error.code', 'outside_window')
            ->assertJsonPath('error.message', 'Absen masuk baru dibuka pukul 06:30.');
    }

    public function test_check_in_on_day_off_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-31 08:00'); // Sabtu

        $this->punch($e)->assertStatus(422)->assertJsonPath('error.code', 'outside_window');
    }

    public function test_photo_without_exactly_one_face_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');

        $this->punch($e, 'in', ['face_count' => 0])->assertStatus(422)->assertJsonPath('error.code', 'no_face');
        $this->punch($e, 'in', ['face_count' => 2])->assertStatus(422)->assertJsonPath('error.code', 'no_face');
    }

    public function test_campus_not_assigned_to_employee_is_office_not_allowed(): void
    {
        $this->makeOffice('Kampus 2', -3.2985, 114.6012, 100);
        $e = $this->makeEmployee(); // hanya boleh di Kampus 1
        $this->at('2026-10-28 07:20');

        $this->punch($e, 'in', ['latitude' => -3.2986, 'longitude' => 114.6012])
            ->assertStatus(422)->assertJsonPath('error.code', 'office_not_allowed');
    }

    public function test_check_in_and_out_may_be_at_different_campuses(): void
    {
        $k2 = $this->makeOffice('Kampus 2', -3.2985, 114.6012, 100);
        $e = $this->makeEmployee([], [$this->kampus1, $k2]);

        $this->at('2026-10-28 07:20');
        $this->punch($e)->assertCreated()->assertJsonPath('data.check_in.office.name', 'Kampus 1');
        $this->at('2026-10-28 16:05');
        $this->punch($e, 'out', ['latitude' => -3.2986, 'longitude' => 114.6012])->assertOk()
            ->assertJsonPath('data.check_out.office.name', 'Kampus 2');
    }

    public function test_check_out_rules(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($e, 'out')->assertStatus(422)->assertJsonPath('error.code', 'not_checked_in');

        $this->punch($e)->assertCreated();
        $this->at('2026-10-28 15:00');
        $this->punch($e, 'out')->assertOk()
            ->assertJsonPath('data.is_early_leave', true)
            ->assertJsonPath('data.worked_minutes', 400); // 07.20–15.00 dikurangi istirahat 60 menit

        $this->at('2026-10-28 16:10');
        $this->punch($e, 'out')->assertStatus(422)->assertJsonPath('error.code', 'already_checked_out');
    }

    public function test_flexible_lecturer_is_never_late_but_judged_on_total_minutes(): void
    {
        $flex = $this->makePattern('Dosen Fleksibel', WorkPatternType::Flexible, array_fill(1, 5, ['06:00', '21:00']), open: 0, minDaily: 360);
        $e = $this->makeEmployee(['work_pattern_id' => $flex->id, 'attendance_type' => 'flexible', 'employee_type' => 'dosen']);

        $this->at('2026-10-28 10:40');
        $this->punch($e)->assertCreated()->assertJsonPath('data.status', 'present')->assertJsonPath('data.late_minutes', 0);
        $this->at('2026-10-28 14:40');
        $this->punch($e, 'out')->assertOk()
            ->assertJsonPath('data.worked_minutes', 240)
            ->assertJsonPath('data.is_early_leave', true); // kurang dari 6 jam
    }

    public function test_inactive_account_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $e->user->update(['is_active' => false]);
        $this->at('2026-10-28 07:20');

        $this->punch($e)->assertStatus(403)->assertJsonPath('error.code', 'inactive_account');
    }

    public function test_today_reports_next_action(): void
    {
        $e = $this->makeEmployee();
        Sanctum::actingAs($e->user);
        $get = fn () => $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/attendance/today');

        $this->at('2026-10-28 06:00');
        $get()->assertOk()->assertJsonPath('data.next_action', 'closed')
            ->assertJsonPath('data.schedule.checkin_opens_at', '06:30')
            ->assertJsonPath('data.schedule.late_after', '07:45')
            ->assertJsonPath('data.server_time', '2026-10-28T06:00:00+08:00');

        $this->at('2026-10-28 07:00');
        $get()->assertJsonPath('data.next_action', 'check_in');
        $this->punch($e)->assertCreated();
        Sanctum::actingAs($e->user);
        $get()->assertJsonPath('data.next_action', 'check_out')->assertJsonPath('data.attendance.status', 'present');

        $this->at('2026-10-31 09:00');
        $get()->assertJsonPath('data.next_action', 'day_off');
    }

    public function test_unknown_device_is_blocked_on_other_endpoints(): void
    {
        $e = $this->makeEmployee();
        Sanctum::actingAs($e->user);

        $this->withHeader('X-Device-UID', 'hp-lain-000000000000')->getJson('/api/v1/me')
            ->assertForbidden()->assertJsonPath('error.code', 'unknown_device');
    }

    public function test_status_enum_is_stored(): void
    {
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:20');
        $this->punch($e)->assertCreated();

        $this->assertSame(AttendanceStatus::Present, Attendance::sole()->status);
    }
}
