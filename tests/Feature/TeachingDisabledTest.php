<?php

namespace Tests\Feature;

use App\Enums\AttendanceType;
use App\Enums\EmployeeType;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Jobs\ProcessAttendancePhoto;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\Employee;
use App\Models\TeachingSchedule;
use App\Models\TeachingSession;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCampus;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/** Presensi perkuliahan yang dinonaktifkan. Rabu 28 Oktober 2026; kuliah 08.00–09.40. */
class TeachingDisabledTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private Employee $lecturer;

    private TeachingSchedule $schedule;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.sessions.enabled' => false]);
        Storage::fake('attendance');
        Queue::fake([ProcessAttendancePhoto::class]);
        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $this->buildCampus();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Pegawai harus sudah terdaftar sebelum tanggal yang diuji (lihat close-day).
        $this->at('2026-10-01 06:00');

        $this->lecturer = $this->makeEmployee(['employee_type' => EmployeeType::Dosen, 'attendance_type' => AttendanceType::Session]);
        $course = Course::create(['institution_id' => $this->inst->id, 'code' => 'IF201', 'name' => 'Pemrograman Web', 'credits' => 3]);
        $this->schedule = TeachingSchedule::create([
            'institution_id' => $this->inst->id, 'employee_id' => $this->lecturer->id, 'course_id' => $course->id,
            'class_name' => 'A', 'weekday' => 3, 'start_time' => '08:00', 'end_time' => '09:40',
            'valid_from' => '2026-09-01', 'valid_until' => '2027-01-31',
        ]);
        $this->admin = $this->makeEmployee()->user;
        $this->admin->assignRole(User::ROLE_ADMIN);
    }

    private function api(): static
    {
        Sanctum::actingAs($this->lecturer->user);

        return $this->withHeader('X-Device-UID', $this->deviceUids[$this->lecturer->id]);
    }

    public function test_teaching_menu_is_hidden_and_closed_in_panel(): void
    {
        $this->actingAs($this->admin);
        foreach (['/admin/courses', '/admin/teaching-schedules', '/admin/teaching-sessions'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/admin')->assertOk()->assertDontSee('Perkuliahan');
    }

    public function test_teaching_menu_returns_when_enabled(): void
    {
        config(['attendance.sessions.enabled' => true]);

        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk()->assertSee('Perkuliahan');
        $this->get('/admin/courses')->assertOk();
    }

    public function test_teaching_api_is_closed(): void
    {
        $this->api()->getJson('/api/v1/teaching/today')->assertNotFound();
        $this->api()->getJson('/api/v1/teaching/history')->assertNotFound();
        $this->api()->postJson("/api/v1/teaching/{$this->schedule->id}/check-in")->assertNotFound();
    }

    public function test_per_session_lecturer_attends_daily(): void
    {
        $this->at('2026-10-28 07:25');

        $this->api()->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.attendance_type', 'fixed')
            ->assertJsonPath('data.teaching_enabled', false);
        $this->api()->getJson('/api/v1/attendance/today')->assertOk()
            ->assertJsonPath('data.next_action', 'check_in')
            ->assertJsonPath('data.teaching', []);
        $this->punch($this->lecturer)->assertSuccessful();

        $this->assertSame(1, Attendance::where('employee_id', $this->lecturer->id)->count());
    }

    public function test_stored_attendance_type_is_kept_for_later(): void
    {
        $this->assertSame(AttendanceType::Session, $this->lecturer->fresh()->attendance_type);
        $this->assertSame(AttendanceType::Fixed, $this->lecturer->attendance_type->effective());

        config(['attendance.sessions.enabled' => true]);
        $this->assertSame(AttendanceType::Session, $this->lecturer->attendance_type->effective());
    }

    public function test_employee_form_does_not_offer_per_session(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateEmployee::class)->assertDontSee('Per sesi mengajar');
        Livewire::test(ListEmployees::class)->assertDontSee('Per sesi mengajar');
    }

    public function test_close_day_treats_lecturer_as_daily_and_skips_sessions(): void
    {
        $this->at('2026-10-28 23:55');
        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame(0, TeachingSession::count());
        $this->assertTrue(Attendance::where('employee_id', $this->lecturer->id)->whereDate('date', '2026-10-28')->exists());
    }

    public function test_no_teaching_reminder_is_sent(): void
    {
        $push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $push);
        $this->lecturer->activeDevice->update(['fcm_token' => 'token-hp']);

        $this->at('2026-10-28 07:50');
        $this->artisan('attendance:remind')->assertSuccessful();

        $this->assertSame([], collect($push->sent)->where('data.type', 'session')->all());
    }
}
