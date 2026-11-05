<?php

namespace Tests\Feature;

use App\Enums\LeaveStatus;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Jobs\ProcessAttendancePhoto;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use App\Services\LeaveService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCampus;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/** Siapa melihat log siapa, dan aktivitas apa saja yang tercatat. */
class ActivityLogTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private User $super;

    private User $otherSuper;

    private User $admin;

    private User $otherAdmin;

    private User $head;

    private User $staff;

    /** @var array<string, ActivityLog> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([User::ROLE_ADMIN, User::ROLE_HEAD, User::ROLE_EMPLOYEE] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Storage::fake('attendance');
        Queue::fake([ProcessAttendancePhoto::class]);
        $this->app->instance(PushNotifier::class, new FakePushNotifier);
        $this->buildCampus();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([
            'super' => User::ROLE_SUPER, 'otherSuper' => User::ROLE_SUPER, 'admin' => User::ROLE_ADMIN,
            'otherAdmin' => User::ROLE_ADMIN, 'head' => User::ROLE_HEAD, 'staff' => User::ROLE_EMPLOYEE,
        ] as $name => $role) {
            $this->{$name} = $this->makeEmployee()->user;
            $this->{$name}->assignRole($role);
            $this->logs[$name] = ActivityLog::record($this->{$name}, 'login', "Aktivitas $name");
        }
    }

    /** @return array<int, string> */
    private function visibleFor(User $viewer): array
    {
        $ids = ActivityLog::visibleTo($viewer)->pluck('id')->all();

        return collect($this->logs)->filter(fn (ActivityLog $log) => in_array($log->id, $ids, true))->keys()->sort()->values()->all();
    }

    public function test_super_admin_sees_everyone(): void
    {
        $this->assertSame(['admin', 'head', 'otherAdmin', 'otherSuper', 'staff', 'super'], $this->visibleFor($this->super));
    }

    public function test_admin_sees_self_and_people_below_only(): void
    {
        $this->assertSame(['admin', 'head', 'staff'], $this->visibleFor($this->admin));
    }

    public function test_unit_head_sees_own_log_only(): void
    {
        $this->assertSame(['head'], $this->visibleFor($this->head));
    }

    public function test_employee_sees_own_log_only(): void
    {
        $this->assertSame(['staff'], $this->visibleFor($this->staff));
    }

    public function test_highest_role_decides_visibility(): void
    {
        $this->staff->assignRole(User::ROLE_SUPER);
        $this->head->assignRole(User::ROLE_ADMIN);

        $this->assertSame(['admin'], $this->visibleFor($this->admin->fresh()));
    }

    public function test_admin_page_shows_only_allowed_rows_and_hides_others_from_filter(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$this->logs['admin'], $this->logs['head'], $this->logs['staff']])
            ->assertCanNotSeeTableRecords([$this->logs['super'], $this->logs['otherSuper'], $this->logs['otherAdmin']]);

        $response = $this->get(ActivityLogResource::getUrl('index', panel: 'admin'))->assertOk();
        foreach ([$this->super, $this->otherSuper, $this->otherAdmin] as $hidden) {
            $response->assertDontSee($hidden->name);
        }
        $response->assertDontSee('Super Admin');
    }

    public function test_detail_shows_changes(): void
    {
        $log = ActivityLog::record($this->staff, 'data', 'Mengubah sesuatu', ['name' => ['Lama', 'Baru'], 'is_active' => [true, false]]);

        $this->assertSame(['Nama: Lama → Baru', 'Aktif: Ya → Tidak'], $log->changeLines());
        $this->actingAs($this->admin);
        Livewire::test(ListActivityLogs::class)
            ->mountAction(TestAction::make('view')->table($log))
            ->assertMountedActionModalSee('Nama: Lama → Baru');
    }

    public function test_log_cannot_be_changed_from_panel(): void
    {
        $this->actingAs($this->super);

        $this->assertFalse(ActivityLogResource::canCreate());
        $this->assertFalse(ActivityLogResource::canEdit($this->logs['staff']));
        $this->assertFalse(ActivityLogResource::canDelete($this->logs['staff']));
    }

    public function test_employee_cannot_open_panel_log(): void
    {
        $this->actingAs($this->staff)->get(ActivityLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
    }

    public function test_panel_login_and_logout_are_recorded(): void
    {
        Auth::login($this->admin);
        Auth::logout();

        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->admin->id, 'action' => 'login', 'description' => 'Masuk ke panel admin']);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->admin->id, 'action' => 'logout', 'description' => 'Keluar dari panel admin']);
    }

    public function test_mobile_login_is_recorded(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login' => $this->staff->email, 'password' => 'password',
            'device_uid' => $this->deviceUids[$this->staff->employee->id], 'device_model' => 'Test Phone',
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->staff->id, 'action' => 'login', 'description' => 'Masuk ke aplikasi mobile (Test Phone)']);
    }

    public function test_check_in_is_recorded_only_when_accepted(): void
    {
        $this->at('2026-10-28 07:25');
        $this->punch($this->staff->employee, overrides: ['face_count' => 0]);
        $this->assertDatabaseMissing('activity_logs', ['user_id' => $this->staff->id, 'action' => 'attendance']);

        $this->punch($this->staff->employee)->assertSuccessful();
        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->staff->id, 'action' => 'attendance', 'description' => 'Absen masuk pukul 07:25 di Kampus 1']);
    }

    public function test_approval_is_recorded_under_approver(): void
    {
        $employee = $this->staff->employee;
        $leave = LeaveRequest::create([
            'institution_id' => $this->inst->id, 'employee_id' => $employee->id, 'type' => 'cuti',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'days' => 1, 'reason' => 'Keluarga', 'status' => LeaveStatus::Pending,
        ]);

        app(LeaveService::class)->approve($leave, $this->admin);

        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->admin->id, 'action' => 'review',
            'description' => "Menyetujui {$leave->typeLabel()} {$this->staff->name} 02 Nov 2026"]);
    }

    public function test_password_change_never_stores_password(): void
    {
        Sanctum::actingAs($this->staff);
        $this->withHeader('X-Device-UID', $this->deviceUids[$this->staff->employee->id]);
        $this->staff->withAccessToken($this->staff->createToken('device:1')->accessToken);

        $this->postJson('/api/v1/me/password', [
            'current_password' => 'password', 'password' => 'rahasia-baru-123', 'password_confirmation' => 'rahasia-baru-123',
        ])->assertOk();

        $log = ActivityLog::where('user_id', $this->staff->id)->where('action', 'account')->sole();
        $this->assertSame('Mengganti password', $log->description);
        $this->assertNull($log->details);
    }

    public function test_editing_employee_records_changes_and_masks_password(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditEmployee::class, ['record' => $this->staff->employee->getRouteKey()])
            ->fillForm(['phone' => '081234567890', 'user_password' => 'rahasia-baru-123', 'user_roles' => [User::ROLE_HEAD]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->admin->id, 'action' => 'account', 'description' => "Mengubah peran {$this->staff->name}"]);
        $employee = ActivityLog::where('description', "Mengubah data pegawai {$this->staff->name}")->sole();
        $this->assertSame([null, '081234567890'], $employee->details['phone']);
        $account = ActivityLog::where('description', "Mengubah akun {$this->staff->name}")->sole();
        $this->assertSame(['••••', '••••'], $account->details['password']);
        $this->assertStringNotContainsString('rahasia-baru-123', ActivityLog::query()->pluck('details')->toJson());
    }

    public function test_scheduled_jobs_are_not_recorded(): void
    {
        $before = ActivityLog::count();
        $this->at('2026-10-28 23:55');

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame($before, ActivityLog::count());
    }
}
