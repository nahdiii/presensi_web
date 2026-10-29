<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\EmployeeType;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\AttendanceAttempt;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\TeachingSchedule;
use App\Models\TeachingSession;
use App\Notifications\Push\PushNotifier;
use App\Services\TeachingScheduleImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsCampus;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Fase 3: presensi per sesi mengajar. Rabu 28 Oktober 2026; kuliah
 * 08.00–09.40, absen dibuka 07.45, terlambat setelah 08.15.
 */
class TeachingSessionTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private Course $course;

    private Office $gedung;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attendance');
        Queue::fake();
        $this->buildCampus();
        // Pegawai harus sudah terdaftar sebelum tanggal yang diuji (lihat close-day).
        $this->at('2026-10-01 06:00');
        $this->course = Course::create(['institution_id' => $this->inst->id, 'code' => 'IF201', 'name' => 'Pemrograman Web', 'credits' => 3]);
        $this->gedung = $this->makeOffice('Gedung Teknik', self::LAT + 0.02, self::LNG, 100); // ±2,2 km dari Kampus 1
    }

    private function lecturer(array $attrs = []): Employee
    {
        return $this->makeEmployee($attrs + ['employee_type' => EmployeeType::Dosen, 'attendance_type' => AttendanceType::Session],
            [$this->kampus1, $this->gedung]);
    }

    private function schedule(Employee $e, array $attrs = []): TeachingSchedule
    {
        return TeachingSchedule::create($attrs + [
            'institution_id' => $this->inst->id, 'employee_id' => $e->id, 'course_id' => $this->course->id,
            'class_name' => 'A', 'weekday' => 3, 'start_time' => '08:00', 'end_time' => '09:40', 'room' => 'R. 2.04',
            'valid_from' => '2026-09-01', 'valid_until' => '2027-01-31',
        ]);
    }

    private function sessionPunch(Employee $e, TeachingSchedule $s, array $overrides = [])
    {
        Sanctum::actingAs($e->user);

        return $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])
            ->post("/api/v1/teaching/{$s->id}/check-in", $overrides + [
                'latitude' => self::LAT + 0.0002, 'longitude' => self::LNG, 'accuracy' => 12,
                'is_mocked' => 0, 'is_rooted' => 0, 'face_count' => 1,
                'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
            ], ['Accept' => 'application/json']);
    }

    // ---------- Absen sesi ----------

    public function test_session_check_in_on_time_is_present_with_topic(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);
        $this->at('2026-10-28 07:50');

        $this->sessionPunch($e, $s, ['topic' => 'Routing di Laravel'])->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.course_name', 'Pemrograman Web')
            ->assertJsonPath('data.topic', 'Routing di Laravel')
            ->assertJsonPath('meta.message', 'Selamat mengajar!');

        $row = TeachingSession::sole();
        $this->assertSame('2026-10-28', $row->date->toDateString());
        $this->assertSame(0, $row->late_minutes);
        Storage::disk('attendance')->assertExists($row->photo_path);
        $this->assertSame('session', AttendanceAttempt::sole()->type);
    }

    public function test_session_check_in_after_tolerance_is_late_from_class_start(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);
        $this->at('2026-10-28 08:20');

        $this->sessionPunch($e, $s)->assertCreated()
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.late_minutes', 20);
    }

    public function test_session_window_is_enforced(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);

        $this->at('2026-10-28 07:40');
        $this->sessionPunch($e, $s)->assertStatus(422)
            ->assertJsonPath('error.code', 'outside_window')
            ->assertJsonPath('error.message', 'Absen sesi baru dibuka pukul 07:45.');

        $this->at('2026-10-28 09:40');
        $this->sessionPunch($e, $s)->assertStatus(422)
            ->assertJsonPath('error.message', 'Sesi sudah berakhir pukul 09:40.');
        $this->assertSame(0, TeachingSession::count());
    }

    public function test_schedule_not_on_this_day_or_outside_semester_is_rejected(): void
    {
        $e = $this->lecturer();
        $thursday = $this->schedule($e, ['weekday' => 4]);
        $ended = $this->schedule($e, ['class_name' => 'B', 'valid_until' => '2026-10-27']);
        $inactive = $this->schedule($e, ['class_name' => 'C', 'is_active' => false]);
        $this->at('2026-10-28 08:00');

        foreach ([$thursday, $ended, $inactive] as $s) {
            $this->sessionPunch($e, $s)->assertStatus(422)->assertJsonPath('error.code', 'outside_window');
        }
    }

    public function test_cannot_check_in_to_another_lecturers_schedule(): void
    {
        $owner = $this->lecturer();
        $other = $this->lecturer();
        $s = $this->schedule($owner);
        $this->at('2026-10-28 08:00');

        $this->sessionPunch($other, $s)->assertForbidden();
    }

    public function test_second_check_in_for_same_session_is_rejected(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);
        $this->at('2026-10-28 07:55');
        $this->sessionPunch($e, $s)->assertCreated();

        $this->at('2026-10-28 08:05');
        $this->sessionPunch($e, $s)->assertStatus(422)
            ->assertJsonPath('error.code', 'already_checked_in')
            ->assertJsonPath('error.message', 'Anda sudah absen sesi ini pukul 07:55.');
    }

    public function test_session_in_a_building_must_be_checked_in_there(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e, ['office_id' => $this->gedung->id]);
        $this->at('2026-10-28 08:00');

        // Di Kampus 1 (lokasi absen dosen), tetapi kuliahnya di Gedung Teknik.
        $this->sessionPunch($e, $s)->assertStatus(422)
            ->assertJsonPath('error.code', 'office_not_allowed')
            ->assertJsonPath('error.message', fn ($m) => str_starts_with($m, 'Sesi ini dijadwalkan di Gedung Teknik.'));

        $this->sessionPunch($e, $s, ['latitude' => self::LAT + 0.0201])->assertCreated()
            ->assertJsonPath('data.office.name', 'Gedung Teknik');
    }

    public function test_session_without_building_accepts_any_allowed_campus(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);
        $this->at('2026-10-28 08:00');

        $this->sessionPunch($e, $s, ['latitude' => self::LAT + 0.0201])->assertCreated()
            ->assertJsonPath('data.office.name', 'Gedung Teknik');
    }

    public function test_session_reuses_daily_evidence_rules(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);
        $this->at('2026-10-28 08:00');

        $this->sessionPunch($e, $s, ['is_mocked' => 1])->assertStatus(422)->assertJsonPath('error.code', 'mock_location');
        $this->sessionPunch($e, $s, ['accuracy' => 80])->assertStatus(422)->assertJsonPath('error.code', 'low_accuracy');
        $this->sessionPunch($e, $s, ['face_count' => 0])->assertStatus(422)->assertJsonPath('error.code', 'no_face');
        $this->sessionPunch($e, $s, ['latitude' => self::LAT + 0.01])->assertStatus(422)->assertJsonPath('error.code', 'out_of_radius');

        Sanctum::actingAs($e->user);
        $this->withHeader('X-Device-UID', str_repeat('x', 32))
            ->post("/api/v1/teaching/{$s->id}/check-in", ['latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 10,
                'is_mocked' => 0, 'face_count' => 1, 'photo' => UploadedFile::fake()->image('s.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(403)->assertJsonPath('error.code', 'unknown_device');

        $this->assertSame(0, TeachingSession::count());
        $this->assertSame(5, AttendanceAttempt::where('type', 'session')->where('result', 'rejected')->count());
    }

    public function test_session_on_campus_holiday_is_rejected(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);
        CalendarEvent::create(['institution_id' => $this->inst->id, 'date' => '2026-10-28', 'name' => 'Dies Natalis', 'type' => 'libur_kampus']);
        $this->at('2026-10-28 08:00');

        $this->sessionPunch($e, $s)->assertStatus(422)
            ->assertJsonPath('error.code', 'outside_window')
            ->assertJsonPath('error.message', 'Hari ini libur (Dies Natalis).');
    }

    // ---------- Beranda ----------

    public function test_today_lists_sessions_with_their_state(): void
    {
        $e = $this->lecturer();
        $first = $this->schedule($e);
        $this->schedule($e, ['class_name' => 'B', 'start_time' => '10:00', 'end_time' => '11:40']);
        $this->schedule($e, ['class_name' => 'C', 'weekday' => 4]); // Kamis, tidak tampil
        $today = fn () => $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/attendance/today');
        Sanctum::actingAs($e->user);

        $this->at('2026-10-28 07:00');
        $today()->assertOk()
            ->assertJsonPath('data.next_action', 'sessions')
            ->assertJsonCount(2, 'data.teaching')
            ->assertJsonPath('data.teaching.0.state', 'upcoming')
            ->assertJsonPath('data.teaching.0.opens_at', '07:45')
            ->assertJsonPath('data.teaching.0.start_time', '08:00');

        $this->at('2026-10-28 07:50');
        $today()->assertJsonPath('data.teaching.0.state', 'open');
        $this->sessionPunch($e, $first)->assertCreated();
        Sanctum::actingAs($e->user);

        $this->at('2026-10-28 11:45');
        $today()->assertJsonPath('data.teaching.0.state', 'done')
            ->assertJsonPath('data.teaching.0.session.status', 'present')
            ->assertJsonPath('data.teaching.1.state', 'missed');
    }

    public function test_lecturer_with_daily_attendance_also_sees_sessions(): void
    {
        $e = $this->lecturer(['attendance_type' => AttendanceType::Fixed]);
        $this->schedule($e);
        $this->at('2026-10-28 08:00');
        Sanctum::actingAs($e->user);

        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/attendance/today')
            ->assertJsonPath('data.next_action', 'check_in')
            ->assertJsonPath('data.teaching.0.state', 'open');
    }

    public function test_leave_marks_session_as_off_on_home(): void
    {
        $e = $this->lecturer();
        $this->schedule($e);
        LeaveRequest::create(['institution_id' => $this->inst->id, 'employee_id' => $e->id, 'type' => LeaveType::Sakit,
            'start_date' => '2026-10-28', 'end_date' => '2026-10-28', 'reason' => 'Demam', 'status' => LeaveStatus::Approved]);
        $this->at('2026-10-28 08:00');
        Sanctum::actingAs($e->user);

        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/teaching/today')
            ->assertJsonPath('data.0.state', 'off')
            ->assertJsonPath('data.0.message', 'Sakit');
    }

    // ---------- Tutup hari ----------

    public function test_close_day_marks_missed_sessions_and_respects_leave_and_holiday(): void
    {
        $held = $this->lecturer();
        $missed = $this->lecturer();
        $sick = $this->lecturer();
        $s1 = $this->schedule($held);
        $this->schedule($missed);
        $this->schedule($sick);
        $evening = $this->schedule($missed, ['class_name' => 'B', 'start_time' => '19:00', 'end_time' => '20:40']);
        LeaveRequest::create(['institution_id' => $this->inst->id, 'employee_id' => $sick->id, 'type' => LeaveType::Sakit,
            'start_date' => '2026-10-28', 'end_date' => '2026-10-28', 'reason' => 'Demam', 'status' => LeaveStatus::Approved]);

        $this->at('2026-10-28 08:00');
        $this->sessionPunch($held, $s1)->assertCreated();

        // Siang: sesi pagi sudah selesai, sesi malam belum.
        $this->at('2026-10-28 12:00');
        $this->artisan('attendance:close-day')->assertSuccessful();
        $status = fn (Employee $e, string $class = 'A') => TeachingSession::where('employee_id', $e->id)->where('class_name', $class)->first()?->status;
        $this->assertSame(AttendanceStatus::Present, $status($held));
        $this->assertSame(AttendanceStatus::Absent, $status($missed));
        $this->assertSame(AttendanceStatus::Sick, $status($sick));
        $this->assertNull($status($missed, 'B'));

        $this->at('2026-10-28 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame(AttendanceStatus::Absent, $status($missed, 'B'));
        $this->assertSame(4, TeachingSession::count());
        $this->assertSame($evening->id, TeachingSession::where('class_name', 'B')->sole()->teaching_schedule_id);

        // Jadwal diedit belakangan tidak mengubah riwayat.
        $s1->update(['room' => 'R. 9.99']);
        $this->assertSame('R. 2.04', TeachingSession::where('employee_id', $held->id)->sole()->room);
    }

    public function test_close_day_records_holiday_for_scheduled_sessions(): void
    {
        $e = $this->lecturer();
        $this->schedule($e);
        CalendarEvent::create(['institution_id' => $this->inst->id, 'date' => '2026-10-28', 'name' => 'Dies Natalis', 'type' => 'libur_kampus']);

        $this->at('2026-10-28 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame(AttendanceStatus::Holiday, TeachingSession::sole()->status);
    }

    public function test_history_summarises_the_month(): void
    {
        $e = $this->lecturer();
        $s = $this->schedule($e);
        $this->at('2026-10-21 08:20');
        $this->sessionPunch($e, $s)->assertCreated();
        $this->at('2026-10-28 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();
        Sanctum::actingAs($e->user);

        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/teaching/history?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.summary.held', 1)
            ->assertJsonPath('data.summary.late', 1)
            ->assertJsonPath('data.summary.absent', 1)
            ->assertJsonPath('data.items.0.date', '2026-10-28');
    }

    // ---------- Pengingat ----------

    public function test_reminder_is_sent_once_when_window_opens(): void
    {
        $push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $push);
        $e = $this->lecturer();
        $e->activeDevice->update(['fcm_token' => 'token-hp']);
        $s = $this->schedule($e);

        $this->at('2026-10-28 07:30');
        $this->artisan('attendance:remind')->assertSuccessful();
        $this->at('2026-10-28 07:50');
        $this->artisan('attendance:remind')->assertSuccessful();
        $this->artisan('attendance:remind')->assertSuccessful();

        $this->assertCount(1, $push->sent);
        $this->assertSame('Kuliah Pemrograman Web (A) pukul 08:00', $push->sent[0]['title']);
        $this->assertSame(['type' => 'session', 'id' => (string) $s->id], $push->sent[0]['data']);
    }

    // ---------- Impor CSV ----------

    public function test_csv_import_creates_updates_and_reports_bad_rows(): void
    {
        $e = $this->lecturer(['nip' => '198805202014041002']);
        $path = tempnam(sys_get_temp_dir(), 'jadwal');
        file_put_contents($path, "\xEF\xBB\xBF".implode(';', TeachingScheduleImporter::COLUMNS)."\n"
            ."198805202014041002;IF305;Jaringan Komputer;2;B;Kamis;10.00;11:40;Lab. Jaringan;Gedung Teknik;2026-09-01;31/01/2027\n"
            ."198805202014041002;IF201;Pemrograman Web Lanjut;3;A;3;08:00;09:40;;;2026-09-01;2027-01-31\n"
            ."000;IF201;Pemrograman Web;3;A;Senin;08:00;09:40;;;2026-09-01;2027-01-31\n"
            ."198805202014041002;IF201;Pemrograman Web;3;A;Senin;10:00;09:00;;;2026-09-01;2027-01-31\n"
            ."198805202014041002;IF201;Pemrograman Web;3;A;Senen;08:00;09:40;;;2026-09-01;2027-01-31\n");

        $importer = app(TeachingScheduleImporter::class);
        $result = $importer->import($path, $this->inst->id);

        $this->assertSame(2, $result['created']);
        $this->assertCount(3, $result['errors']);
        $this->assertStringContainsString('Baris 4: pegawai dengan NIP 000', $result['errors'][0]);
        $jarkom = TeachingSchedule::whereHas('course', fn ($q) => $q->where('code', 'IF305'))->sole();
        $this->assertSame(4, $jarkom->weekday);
        $this->assertSame('10:00:00', $jarkom->start_time);
        $this->assertSame('2027-01-31', $jarkom->valid_until->toDateString());
        $this->assertSame($this->gedung->id, $jarkom->office_id);
        $this->assertSame('Pemrograman Web Lanjut', $this->course->fresh()->name); // MK diperbarui berdasarkan kode

        // Impor ulang memperbarui, tidak menduplikasi.
        $again = $importer->import($path, $this->inst->id);
        $this->assertSame(0, $again['created']);
        $this->assertSame(2, $again['updated']);
        $this->assertSame(2, TeachingSchedule::where('employee_id', $e->id)->count());
        @unlink($path);
    }

    public function test_csv_without_required_columns_is_rejected_whole(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jadwal');
        file_put_contents($path, "nip,kode_mk\n123,IF201\n");

        $result = app(TeachingScheduleImporter::class)->import($path, $this->inst->id);

        $this->assertSame(0, $result['created']);
        $this->assertStringStartsWith('Kolom wajib tidak ada: nama_mk', $result['errors'][0]);
        @unlink($path);
    }
}
