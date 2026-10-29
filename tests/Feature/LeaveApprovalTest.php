<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\UnitType;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Jobs\ProcessAttendancePhoto;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use App\Services\CorrectionService;
use App\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCampus;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Validasi dan jatah cuti, notifikasi pengajuan ke pimpinan, dan presensi
 * manual oleh Admin SDM. Rabu 28 Oktober 2026; pola Senin–Jumat.
 */
class LeaveApprovalTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private FakePushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attendance');
        // Hanya kompres foto yang dipalsukan; notifikasi lonceng Filament dikirim lewat antrean.
        Queue::fake([ProcessAttendancePhoto::class]);
        $this->push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->push);
        foreach ([User::ROLE_ADMIN, User::ROLE_HEAD, User::ROLE_EMPLOYEE] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->buildCampus();
        $this->at('2026-10-28 09:00');
    }

    private function submit(Employee $e, array $data)
    {
        Sanctum::actingAs($e->user);

        return $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])
            ->postJson('/api/v1/leaves', $data + ['type' => 'cuti', 'reason' => 'Urusan keluarga']);
    }

    // ---------- Validasi & jatah cuti ----------

    public function test_leave_cannot_be_backdated_too_far(): void
    {
        $e = $this->makeEmployee();

        $this->submit($e, ['type' => 'sakit', 'start_date' => '2026-10-20', 'end_date' => '2026-10-20'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Tanggal mulai paling lama 7 hari ke belakang.');
        $this->submit($e, ['type' => 'sakit', 'start_date' => '2026-10-21', 'end_date' => '2026-10-21'])->assertCreated();
    }

    public function test_overlapping_leave_is_rejected_but_rejected_one_frees_the_dates(): void
    {
        $e = $this->makeEmployee();
        $id = $this->submit($e, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->assertCreated()->json('data.id');

        $this->submit($e, ['type' => 'izin', 'start_date' => '2026-11-04', 'end_date' => '2026-11-05'])
            ->assertStatus(422)->assertJsonPath('error.message', fn ($m) => str_starts_with($m, 'Bertumpuk dengan pengajuan Cuti 02 Nov'));

        LeaveRequest::find($id)->update(['status' => LeaveStatus::Rejected]);
        $this->submit($e, ['type' => 'izin', 'start_date' => '2026-11-04', 'end_date' => '2026-11-05'])->assertCreated();
    }

    public function test_range_without_working_days_is_rejected_and_days_are_counted(): void
    {
        $e = $this->makeEmployee();

        $this->submit($e, ['start_date' => '2026-10-31', 'end_date' => '2026-11-01'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Rentang tanggal ini tidak berisi hari kerja.');
        // Jumat–Selasa = 3 hari kerja.
        $this->submit($e, ['start_date' => '2026-10-30', 'end_date' => '2026-11-03'])->assertCreated()->assertJsonPath('data.days', 3);
    }

    public function test_annual_leave_quota_counts_pending_and_approved_days(): void
    {
        $e = $this->makeEmployee(['annual_leave_quota' => 4]);
        $this->submit($e, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->assertCreated(); // 3 hari, menunggu

        $this->submit($e, ['start_date' => '2026-11-09', 'end_date' => '2026-11-10'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Sisa cuti tahun 2026 tinggal 1 hari kerja; pengajuan ini 2 hari.');
        // Izin dan sakit tidak memakai jatah cuti.
        $this->submit($e, ['type' => 'izin', 'start_date' => '2026-11-09', 'end_date' => '2026-11-10'])->assertCreated();

        Sanctum::actingAs($e->user);
        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/leaves')
            ->assertJsonPath('meta.leave_quota', ['year' => 2026, 'quota' => 4, 'used' => 0, 'pending' => 3, 'remaining' => 1]);

        app(LeaveService::class)->approve(LeaveRequest::where('type', LeaveType::Cuti)->sole(), User::first());
        $this->assertSame(['year' => 2026, 'quota' => 4, 'used' => 3, 'pending' => 0, 'remaining' => 1],
            app(LeaveService::class)->quota($e->fresh(), 2026));
    }

    public function test_quota_is_per_calendar_year(): void
    {
        $e = $this->makeEmployee(['annual_leave_quota' => 2]);
        $this->at('2026-12-28 09:00');

        // 30–31 Des 2026 (2 hari) + 1 Jan 2027 (1 hari): muat di jatah tiap tahun.
        $this->submit($e, ['start_date' => '2026-12-30', 'end_date' => '2027-01-01'])->assertCreated()->assertJsonPath('data.days', 3);
        $this->assertSame(0, app(LeaveService::class)->quota($e, 2026)['remaining']);
        $this->assertSame(1, app(LeaveService::class)->quota($e, 2027)['remaining']);
    }

    public function test_default_quota_comes_from_config(): void
    {
        $e = $this->makeEmployee();

        $this->assertSame(12, app(LeaveService::class)->quota($e, 2026)['quota']);
    }

    // ---------- Notifikasi ke pimpinan ----------

    public function test_new_leave_notifies_nearest_unit_head(): void
    {
        $faculty = Unit::create(['institution_id' => $this->inst->id, 'name' => 'Fakultas Teknik', 'type' => UnitType::Fakultas]);
        $this->unit->update(['parent_id' => $faculty->id]);
        $dekan = $this->makeEmployee(['unit_id' => $faculty->id]);
        $kabiro = $this->makeEmployee();
        $faculty->update(['head_employee_id' => $dekan->id]);
        $this->unit->update(['head_employee_id' => $kabiro->id]);
        $staff = $this->makeEmployee();

        $this->submit($staff, ['start_date' => '2026-11-02', 'end_date' => '2026-11-02'])->assertCreated();
        $this->assertSame(1, $kabiro->user->notifications()->count());
        $this->assertSame(0, $dekan->user->notifications()->count());
        $this->assertSame('Pengajuan Cuti', $kabiro->user->notifications()->first()->data['title']);
        $this->assertSame(['employee_id' => $kabiro->id, 'type' => 'approval'],
            ['employee_id' => $this->push->sent[0]['employee_id'], 'type' => $this->push->sent[0]['data']['type']]);

        // Kepala biro mengajukan sendiri: naik ke dekan.
        $this->submit($kabiro, ['start_date' => '2026-11-03', 'end_date' => '2026-11-03'])->assertCreated();
        $this->assertSame(1, $dekan->user->notifications()->count());
    }

    public function test_without_unit_head_admins_are_notified(): void
    {
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin SDM', 'email' => 'admin@kampus.test', 'password' => 'x']);
        $admin->assignRole(User::ROLE_ADMIN);
        $e = $this->makeEmployee();

        $this->submit($e, ['type' => 'sakit', 'start_date' => '2026-10-28', 'end_date' => '2026-10-28'])->assertCreated();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame([], $this->push->sent); // admin tanpa data pegawai: hanya lonceng panel
    }

    public function test_corrections_and_exit_permits_notify_the_head(): void
    {
        $head = $this->makeEmployee();
        $this->unit->update(['head_employee_id' => $head->id]);
        $e = $this->makeEmployee();
        Sanctum::actingAs($e->user);
        $api = $this->withHeader('X-Device-UID', $this->deviceUids[$e->id]);

        $api->postJson('/api/v1/corrections', ['date' => '2026-10-27', 'check_in_time' => '07:30', 'reason' => 'HP mati'])->assertCreated();
        $this->at('2026-10-28 07:25');
        $this->punch($e)->assertCreated();
        $this->at('2026-10-28 10:00');
        Sanctum::actingAs($e->user);
        $api->postJson('/api/v1/exit-permits', ['reason' => 'Ke bank'])->assertCreated();

        $this->assertEqualsCanonicalizing(['Koreksi lupa absen', 'Izin keluar kampus'],
            $head->user->notifications()->get()->pluck('data.title')->all());
    }

    // ---------- Presensi manual oleh admin ----------

    public function test_admin_can_record_attendance_manually_with_audit_trail(): void
    {
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin SDM', 'email' => 'admin@kampus.test', 'password' => 'x']);
        $admin->assignRole(User::ROLE_ADMIN);
        $e = $this->makeEmployee();
        $this->at('2026-10-28 20:00');

        app(CorrectionService::class)->recordManual($e, '2026-10-28', '07:50', '16:05', 'HP rusak, hadir dikonfirmasi kepala biro', $admin);

        $a = Attendance::where('employee_id', $e->id)->sole();
        $this->assertSame(AttendanceStatus::Late, $a->status);
        $this->assertSame(20, $a->late_minutes);
        $this->assertSame('Dicatat manual: HP rusak, hadir dikonfirmasi kepala biro', $a->note);
        $c = AttendanceCorrection::sole();
        $this->assertSame(LeaveStatus::Approved, $c->status);
        $this->assertSame($admin->id, $c->approved_by);
        $this->assertSame('Presensi dicatat oleh Admin SDM', $this->push->sent[0]['title']);
    }

    public function test_manual_action_in_panel_is_admin_only(): void
    {
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin SDM', 'email' => 'admin@kampus.test', 'password' => 'x']);
        $admin->assignRole(User::ROLE_ADMIN);
        $headEmployee = $this->makeEmployee();
        $headEmployee->user->assignRole(User::ROLE_HEAD);
        $this->unit->update(['head_employee_id' => $headEmployee->id]);
        $e = $this->makeEmployee();

        $this->actingAs($admin);
        Livewire::test(ListAttendances::class)
            ->callAction('manual', ['employee_id' => $e->id, 'date' => '2026-10-27', 'check_in' => '07:30', 'reason' => 'Gangguan server'])
            ->assertHasNoActionErrors();
        $this->assertSame(AttendanceStatus::Present, Attendance::where('employee_id', $e->id)->sole()->status);

        $this->actingAs($headEmployee->user);
        Livewire::test(ListAttendances::class)->assertActionHidden('manual');
    }
}
