<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\UnitType;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveTypes\Pages\ManageLeaveTypes;
use App\Jobs\ProcessAttendancePhoto;
use App\Models\Attendance;
use App\Models\DeductionRuleSet;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use App\Services\LeaveService;
use App\Services\RecalculationService;
use App\Support\AttachmentFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCampus;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Potongan izin per jenis, izin bersama untuk rekan, dan lampiran ganda.
 * Rabu 28 Oktober 2026; pola Senin–Jumat.
 */
class LeaveGroupTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private FakePushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attendance');
        Queue::fake([ProcessAttendancePhoto::class]);
        $this->push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->push);
        foreach ([User::ROLE_ADMIN, User::ROLE_HEAD, User::ROLE_EMPLOYEE] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->buildCampus();
        $this->tendikPattern->recordVersion('2000-01-01', 'Jadwal awal');
        $this->at('2026-10-28 09:00');
    }

    /** Potongan per hari di master Jenis Izin, mis. ['izin' => 25000]. */
    private function amounts(array $perCode): void
    {
        foreach ($perCode as $code => $amount) {
            LeaveType::findCode($this->inst->id, $code)->update(['deduction_per_day' => $amount]);
        }
    }

    private function submit(Employee $e, array $data)
    {
        Sanctum::actingAs($e->user);

        return $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])
            ->post('/api/v1/leaves', $data + ['type' => 'tugas_luar', 'reason' => 'Seminar nasional',
                'start_date' => '2026-11-02', 'end_date' => '2026-11-03'], ['Accept' => 'application/json']);
    }

    private function leave(Employee $e, string $type, string $date = '2026-10-28'): LeaveRequest
    {
        return LeaveRequest::create([
            'institution_id' => $this->inst->id, 'employee_id' => $e->id, 'type' => $type,
            'start_date' => $date, 'end_date' => $date, 'reason' => 'x', 'status' => LeaveStatus::Pending,
        ]);
    }

    private function row(Employee $e, string $date = '2026-10-28'): Attendance
    {
        return Attendance::with('leaveRequest')->where('employee_id', $e->id)->whereDate('date', $date)->sole();
    }

    /** Gambar uji: pola kotak-kotak acak (deterministik dari $seed). */
    private function image(int $seed, int $width = 320, int $quality = 90): UploadedFile
    {
        mt_srand($seed);
        $img = imagecreatetruecolor($width, (int) ($width * 1.3));
        $cell = intdiv($width, 8);
        for ($y = 0; $y < 11; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $v = mt_rand(0, 255);
                imagefilledrectangle($img, $x * $cell, $y * $cell, ($x + 1) * $cell, ($y + 1) * $cell, imagecolorallocate($img, $v, $v, $v));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        imagejpeg($img, $path, $quality);
        imagedestroy($img);

        return new UploadedFile($path, "surat-$seed.jpg", 'image/jpeg', null, true);
    }

    // ---------- Potongan izin per jenis ----------

    public function test_each_leave_type_has_its_own_daily_deduction(): void
    {
        $this->amounts(['izin' => 25000, 'sakit' => 10000, 'cuti' => 0]);
        $izin = $this->makeEmployee();
        $cuti = $this->makeEmployee();
        $sakit = $this->makeEmployee();
        $svc = app(LeaveService::class);
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);

        // Izin dan Cuti sama-sama berstatus "izin" di presensi, tetapi potongannya beda.
        $svc->approve($this->leave($izin, 'izin'), $admin);
        $svc->approve($this->leave($cuti, 'cuti'), $admin);
        $this->assertSame(AttendanceStatus::Leave, $this->row($cuti)->status);
        $this->assertSame(25000, $this->row($izin)->deduction_amount);
        $this->assertSame(['Izin'], $this->row($izin)->deductionCodes());
        $this->assertSame(0, $this->row($cuti)->deduction_amount);
        $this->assertSame([], $this->row($cuti)->deductionCodes());

        // Disetujui sebelum hari H: baris dibuat saat tutup hari, tetap memakai jenisnya.
        $leave = $this->leave($sakit, 'sakit', '2026-10-29');
        $svc->approve($leave, $admin);
        $this->at('2026-10-29 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame($leave->id, $this->row($sakit, '2026-10-29')->leave_request_id);
        $this->assertSame(10000, $this->row($sakit, '2026-10-29')->deduction_amount);
    }

    public function test_deduction_is_frozen_when_submitted_and_master_changes_only_affect_new_requests(): void
    {
        $this->amounts(['izin' => 80000]); // Tidak dibatasi potongan alpa (Rp50.000).
        $old = $this->makeEmployee();
        $pending = $this->makeEmployee();
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        $approved = $this->leave($old, 'izin');
        $waiting = $this->leave($pending, 'izin', '2026-10-30');
        $this->assertSame(80000, $approved->deduction_per_day);
        app(LeaveService::class)->approve($approved, $admin);
        $this->assertSame(80000, $this->row($old)->deduction_amount);

        // Admin menurunkan potongan Izin di master.
        $this->amounts(['izin' => 15000]);
        app(RecalculationService::class)->run($this->inst->id, '2026-10-01', '2026-10-28');
        $this->assertSame(80000, $this->row($old)->deduction_amount); // Presensi lama tetap.
        $this->assertSame(80000, $waiting->fresh()->deduction_per_day); // Pengajuan yang sudah dibuat tetap.
        $this->assertSame(['amount' => 80000, 'final' => false], $waiting->fresh()->deductionSummary());

        // Pengajuan baru memakai nominal baru, tanpa perlu aturan potongan.
        $this->assertSame(0, DeductionRuleSet::count());
        $this->submit($this->makeEmployee(), ['type' => 'izin'])->assertCreated();
        $this->assertSame(15000, LeaveRequest::latest('id')->first()->deduction_per_day);
    }

    public function test_admin_manages_leave_types(): void
    {
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x', 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin);
        $this->get('/admin/leave-types')->assertOk()->assertSee('Cuti')->assertSee('Tugas luar');

        Livewire::test(ManageLeaveTypes::class)
            ->callAction('create', ['name' => 'Cuti melahirkan', 'code' => 'cuti_melahirkan', 'attendance_status' => 'leave',
                'deduction_per_day' => 0, 'annual_quota_days' => 90, 'requires_attachment' => true, 'is_active' => true])
            ->assertHasNoActionErrors();
        // Kode harus unik dan berformat kode.
        Livewire::test(ManageLeaveTypes::class)
            ->callAction('create', ['name' => 'Ganda', 'code' => 'cuti', 'attendance_status' => 'leave', 'deduction_per_day' => 0])
            ->assertHasActionErrors(['code' => 'unique']);
        Livewire::test(ManageLeaveTypes::class)
            ->callAction('create', ['name' => 'Salah', 'code' => 'Cuti Besar', 'attendance_status' => 'leave', 'deduction_per_day' => 0])
            ->assertHasActionErrors(['code' => 'regex']);

        $type = LeaveType::findCode($this->inst->id, 'cuti_melahirkan');
        $this->assertSame([90, true, AttendanceStatus::Leave], [$type->annual_quota_days, $type->requires_attachment, $type->attendance_status]);

        // Ubah potongan Izin lewat form.
        $izin = LeaveType::findCode($this->inst->id, 'izin');
        Livewire::test(ManageLeaveTypes::class)
            ->callTableAction('edit', $izin, ['deduction_per_day' => 30000])->assertHasNoTableActionErrors();
        $this->assertSame(30000, $izin->fresh()->deduction_per_day);

        // Jenis yang sudah dipakai tidak bisa dihapus.
        $this->leave($this->makeEmployee(), 'izin', '2026-11-02');
        Livewire::test(ManageLeaveTypes::class)
            ->assertTableActionHidden('delete', $izin)
            ->assertTableActionVisible('delete', $type);
    }

    public function test_custom_leave_type_rules_apply_to_requests(): void
    {
        LeaveType::create(['institution_id' => $this->inst->id, 'code' => 'cuti_melahirkan', 'name' => 'Cuti melahirkan',
            'attendance_status' => 'leave', 'annual_quota_days' => 3, 'requires_attachment' => true, 'deduction_per_day' => 5000]);
        LeaveType::findCode($this->inst->id, 'sakit')->update(['is_active' => false]);
        $e = $this->makeEmployee();
        Sanctum::actingAs($e->user);

        // Sakit dinonaktifkan: tidak ditawarkan; jenis baru ada di urutan terakhir.
        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonCount(4, 'meta.leave_types')
            ->assertJsonPath('meta.leave_types.3.code', 'cuti_melahirkan')
            ->assertJsonPath('meta.leave_types.3.quota_remaining', 3)
            ->assertJsonPath('meta.leave_types.3.requires_attachment', true)
            ->assertJsonPath('meta.leave_types.3.deduction_per_day', 5000)
            ->assertJsonMissing(['code' => 'sakit']);

        $data = ['type' => 'cuti_melahirkan', 'start_date' => '2026-11-02', 'end_date' => '2026-11-05'];
        $this->submit($e, $data)->assertStatus(422)->assertJsonPath('error.message', 'Lampiran wajib untuk Cuti melahirkan.');
        $this->submit($e, $data + ['attachment' => $this->image(11)])->assertStatus(422)
            ->assertJsonPath('error.message', 'Sisa cuti melahirkan tahun 2026 tinggal 3 hari kerja; pengajuan ini 4 hari.');
        $this->submit($e, ['type' => 'cuti_melahirkan', 'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'attachment' => $this->image(12)])
            ->assertCreated()->assertJsonPath('data.type_label', 'Cuti melahirkan')->assertJsonPath('data.deduction.amount', 15000);
        $this->submit($e, ['type' => 'sakit', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09'])->assertStatus(422)
            ->assertJsonPath('error.message', 'Jenis izin tidak tersedia.');
    }

    public function test_api_reports_leave_deductions_and_codes(): void
    {
        $this->amounts(['izin' => 25000]);
        $e = $this->makeEmployee();
        Sanctum::actingAs($e->user);

        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('meta.leave_deductions.izin', 25000)
            ->assertJsonPath('meta.leave_deductions.cuti', 0)
            ->assertJsonPath('meta.group_types', ['izin', 'tugas_luar']);

        app(LeaveService::class)->approve($this->leave($e, 'izin'),
            User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']));
        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/attendance/history?month=2026-10')
            ->assertOk()->assertJsonPath('data.items.0.deduction_label', 'Izin')->assertJsonPath('data.items.0.deduction_amount', 25000);
    }

    // ---------- Batalkan izin hari ini ----------

    public function test_employee_cancels_todays_leave_and_checks_in(): void
    {
        $this->amounts(['cuti' => 25000]);
        $kabiro = $this->makeEmployee();
        $this->unit->update(['head_employee_id' => $kabiro->id]);
        $e = $this->makeEmployee();
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        $this->at('2026-10-27 06:00');
        $leave = LeaveRequest::create(['institution_id' => $this->inst->id, 'employee_id' => $e->id, 'type' => 'cuti', 'days' => 3,
            'start_date' => '2026-10-27', 'end_date' => '2026-10-29', 'reason' => 'Keluarga', 'status' => LeaveStatus::Pending]);
        app(LeaveService::class)->approve($leave, $admin);
        $this->assertSame(3, app(LeaveService::class)->quota($e, 2026)['used']);

        $this->at('2026-10-28 07:00');
        Sanctum::actingAs($e->user);
        $api = fn () => $this->withHeader('X-Device-UID', $this->deviceUids[$e->id]);
        $api()->getJson('/api/v1/attendance/today')->assertOk()
            ->assertJsonPath('data.next_action', 'on_leave')
            ->assertJsonPath('data.leave.id', $leave->id)
            ->assertJsonPath('data.leave.type_label', 'Cuti');

        $api()->postJson("/api/v1/leaves/{$leave->id}/cancel-today")->assertOk()
            ->assertJsonPath('data.cancelled_dates', ['2026-10-28'])
            ->assertJsonPath('data.days', 2)
            ->assertJsonPath('data.status', 'approved');

        // Hari ini bukan izin lagi; hari lain tetap.
        $this->assertSame(0, Attendance::where('employee_id', $e->id)->whereDate('date', '2026-10-28')->count());
        $this->assertSame(25000, $this->row($e, '2026-10-27')->deduction_amount);
        $this->assertSame(25000, $this->row($e, '2026-10-29')->deduction_amount);
        $this->assertSame(2, app(LeaveService::class)->quota($e, 2026)['used']); // Jatah kembali satu hari.
        $this->assertSame(['amount' => 50000, 'final' => true], $leave->fresh()->load('attendances')->deductionSummary());
        $this->assertStringContainsString('masuk kerja pada 28 Okt 2026', $kabiro->user->notifications()->first()->data['body']);

        $api()->getJson('/api/v1/attendance/today')->assertJsonPath('data.next_action', 'check_in')->assertJsonPath('data.leave', null);
        $this->at('2026-10-28 07:20');
        $this->punch($e)->assertCreated();

        // Tidak bisa dibatalkan dua kali.
        $api()->postJson("/api/v1/leaves/{$leave->id}/cancel-today")->assertStatus(422)
            ->assertJsonPath('error.message', 'Hari itu sudah dibatalkan.');
    }

    public function test_cancel_today_rules(): void
    {
        $e = $this->makeEmployee();
        $other = $this->makeEmployee();
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        $pending = $this->leave($e, 'izin');
        $tomorrow = $this->leave($e, 'izin', '2026-10-29');
        app(LeaveService::class)->approve($tomorrow, $admin);
        $notMine = $this->leave($other, 'izin');
        Sanctum::actingAs($e->user);
        $post = fn (LeaveRequest $l) => $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])
            ->postJson("/api/v1/leaves/{$l->id}/cancel-today");

        $post($pending)->assertStatus(422)->assertJsonPath('error.message', 'Hanya izin yang sudah disetujui yang bisa dibatalkan per hari.');
        $post($tomorrow)->assertStatus(422)->assertJsonPath('error.message', 'Tanggal itu tidak termasuk dalam izin ini.');
        $post($notMine)->assertNotFound();

        // Membatalkan hari ini lalu tidak absen: tutup hari mencatat alpa.
        $today = $this->leave($other, 'sakit', '2026-10-28');
        $today->update(['employee_id' => $e->id]);
        app(LeaveService::class)->approve($today->fresh(), $admin);
        $post($today)->assertOk();
        $this->at('2026-10-28 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame(AttendanceStatus::Absent, $this->row($e)->status);
    }

    public function test_admin_cancels_a_past_leave_day_from_detail(): void
    {
        $e = $this->makeEmployee();
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        $admin->assignRole(User::ROLE_ADMIN);
        $this->at('2026-10-27 06:00');
        $leave = $this->leave($e, 'izin', '2026-10-27');
        $leave->update(['end_date' => '2026-10-28', 'days' => 2]);
        app(LeaveService::class)->approve($leave->fresh(), $admin);

        $this->at('2026-10-28 09:00');
        $this->actingAs($admin);
        Livewire::test(ListLeaveRequests::class)
            ->filterTable('status', LeaveStatus::Approved->value)
            ->mountTableAction(['view', 'cancelDay'], $leave)
            ->setActionData(['date' => '2026-10-27', 'note' => 'Hadir rapat'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $leave->refresh();
        $this->assertSame(['2026-10-27'], $leave->cancelled_dates);
        $this->assertSame(1, $leave->days);
        $past = $this->row($e, '2026-10-27');
        $this->assertSame(AttendanceStatus::Absent, $past->status); // Sudah lewat: catat manual bila ia hadir.
        $this->assertNull($past->leave_request_id);
        $this->assertSame(AttendanceStatus::Leave, $this->row($e)->status);
        $this->assertSame('Izin 27 Okt 2026 dibatalkan', collect($this->push->sent)->last()['title']);

        Livewire::test(ListLeaveRequests::class)->filterTable('status', LeaveStatus::Approved->value)
            ->mountTableAction('view', $leave)->assertMountedActionModalSee(['Hari dibatalkan', '27 Okt 2026']);
    }

    // ---------- Izin bersama ----------

    public function test_group_leave_creates_one_request_per_member_with_their_own_approver(): void
    {
        $other = Unit::create(['institution_id' => $this->inst->id, 'name' => 'Fakultas Teknik', 'type' => UnitType::Fakultas,
            'work_pattern_id' => $this->tendikPattern->id]);
        $kabiro = $this->makeEmployee();
        $dekan = $this->makeEmployee(['unit_id' => $other->id]);
        $this->unit->update(['head_employee_id' => $kabiro->id]);
        $other->update(['head_employee_id' => $dekan->id]);
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $c = $this->makeEmployee(['unit_id' => $other->id]);

        $this->submit($a, ['member_ids' => [$b->id, $c->id]])->assertCreated()
            ->assertJsonPath('data.group_members', [$b->name, $c->name]);

        $leaves = LeaveRequest::orderBy('id')->get();
        $this->assertCount(3, $leaves);
        $this->assertSame([$a->id, $b->id, $c->id], $leaves->pluck('employee_id')->all());
        $this->assertCount(1, $leaves->pluck('group_id')->unique());
        $this->assertNotNull($leaves[0]->group_id);
        $this->assertSame([$a->id], $leaves->pluck('submitted_by')->unique()->values()->all());
        $this->assertSame([2, 2, 2], $leaves->pluck('days')->all());

        // A dan B satu unit: kepala biro mendapat satu notifikasi untuk keduanya; C ke dekannya.
        $this->assertSame(1, $kabiro->user->notifications()->count());
        $this->assertStringContainsString("{$a->name}, {$b->name}", $kabiro->user->notifications()->first()->data['body']);
        $this->assertSame(1, $dekan->user->notifications()->count());
        $this->assertStringContainsString("diajukan oleh {$a->name}", $dekan->user->notifications()->first()->data['body']);
        // Rekan diberi tahu bahwa ia diajukan.
        $this->assertSame([$b->id, $c->id], collect($this->push->sent)->where('title', 'Tugas luar untuk Anda')->pluck('employee_id')->all());

        // Rekan melihat pengajuannya sendiri beserta pengajunya.
        Sanctum::actingAs($b->user);
        $this->withHeader('X-Device-UID', $this->deviceUids[$b->id])->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('data.0.submitted_by_name', $a->name)
            ->assertJsonPath('data.0.group_members', [$a->name, $c->name]);

        // Persetujuan per anggota: menyetujui B tidak menyetujui yang lain.
        app(LeaveService::class)->approve($leaves[1], $kabiro->user);
        $this->assertSame([LeaveStatus::Pending, LeaveStatus::Approved, LeaveStatus::Pending], $leaves->map->fresh()->pluck('status')->all());
    }

    public function test_sick_and_annual_leave_cannot_include_colleagues(): void
    {
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();

        foreach (['sakit', 'cuti'] as $type) {
            $this->submit($a, ['type' => $type, 'member_ids' => [$b->id]])->assertStatus(422)
                ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'tidak bisa diajukan bersama rekan'));
        }
        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_one_failing_member_stops_the_whole_group(): void
    {
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $this->leave($b, 'izin', '2026-11-03');

        $this->submit($a, ['member_ids' => [$b->id]])->assertStatus(422)
            ->assertJsonPath('error.message', fn ($m) => str_starts_with($m, "{$b->name}: bertumpuk dengan pengajuan Izin"));
        $this->assertSame(0, LeaveRequest::where('employee_id', $a->id)->count());

        $inactive = $this->makeEmployee();
        $inactive->user->update(['is_active' => false]);
        $this->submit($a, ['member_ids' => [$inactive->id]])->assertStatus(422)
            ->assertJsonPath('error.message', "{$inactive->name}: akun tidak aktif.");
    }

    public function test_colleague_search_by_name_or_nip(): void
    {
        $me = $this->makeEmployee();
        $budi = $this->makeEmployee(['nip' => '199207012019031003']);
        $budi->user->update(['name' => 'Budi Santoso']);
        $gone = $this->makeEmployee();
        $gone->user->update(['name' => 'Budi Lama', 'is_active' => false]);
        Sanctum::actingAs($me->user);
        $get = fn (string $q) => $this->withHeader('X-Device-UID', $this->deviceUids[$me->id])->getJson('/api/v1/colleagues?q='.urlencode($q));

        $get('budi')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $budi->id)
            ->assertJsonPath('data.0.unit_name', 'Biro SDM');
        $get('19920701')->assertOk()->assertJsonPath('data.0.name', 'Budi Santoso');
        $get($me->user->name)->assertOk()->assertJsonCount(0, 'data'); // Diri sendiri tidak ditawarkan.
        $get('%')->assertStatus(422);
    }

    // ---------- Lampiran ganda ----------

    public function test_identical_attachment_is_rejected_unless_shared_in_a_group(): void
    {
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $c = $this->makeEmployee();
        $file = UploadedFile::fake()->createWithContent('surat-tugas.pdf', '%PDF-1.4 surat tugas no 12');

        // Satu lampiran untuk seluruh anggota izin bersama.
        $this->submit($a, ['member_ids' => [$b->id], 'attachment' => $file])->assertCreated();
        $this->assertSame(1, LeaveRequest::pluck('attachment_path')->unique()->count());
        Storage::disk('attendance')->assertExists(LeaveRequest::value('attachment_path'));

        // Pegawai lain mengunggah file yang sama untuk pengajuannya sendiri.
        $this->submit($c, ['attachment' => UploadedFile::fake()->createWithContent('salinan.pdf', '%PDF-1.4 surat tugas no 12')])
            ->assertStatus(422)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'sudah dipakai di pengajuan pegawai lain'));
        // Pemiliknya memakai ulang untuk izin lain.
        $this->submit($a, ['type' => 'izin', 'start_date' => '2026-11-05', 'end_date' => '2026-11-05',
            'attachment' => UploadedFile::fake()->createWithContent('lagi.pdf', '%PDF-1.4 surat tugas no 12')])
            ->assertStatus(422)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'sudah Anda pakai'));
        $this->assertSame(2, LeaveRequest::count());
        $this->assertCount(1, Storage::disk('attendance')->allFiles()); // File yang ditolak tidak disimpan.

        // Pengajuan yang ditolak membebaskan lampirannya.
        LeaveRequest::query()->update(['status' => LeaveStatus::Rejected]);
        $this->submit($c, ['attachment' => UploadedFile::fake()->createWithContent('salinan.pdf', '%PDF-1.4 surat tugas no 12')])
            ->assertCreated();
    }

    public function test_recompressed_photo_of_same_document_is_flagged_for_the_approver(): void
    {
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $c = $this->makeEmployee();

        $first = $this->submit($a, ['attachment' => $this->image(7)])->assertCreated()->json('data.id');
        // Foto yang sama setelah diperkecil dan dikompres ulang (mis. diteruskan lewat WhatsApp).
        $copy = $this->submit($b, ['attachment' => $this->image(7, 200, 45)])->assertCreated()->json('data.id');
        $fresh = $this->submit($c, ['attachment' => $this->image(99)])->assertCreated()->json('data.id');

        $this->assertSame($first, LeaveRequest::find($copy)->similar_leave_request_id);
        $this->assertNull(LeaveRequest::find($fresh)->similar_leave_request_id);
        $this->assertNull(LeaveRequest::find($first)->similar_leave_request_id);

        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin);
        Livewire::test(ListLeaveRequests::class)->assertSee("Mirip lampiran {$a->name}");
    }

    public function test_admin_opens_leave_detail_and_approves_from_it(): void
    {
        $this->amounts(['tugas_luar' => 10000]);
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $this->submit($a, ['member_ids' => [$b->id], 'attachment' => $this->image(3)])->assertCreated();
        [$mine, $theirs] = LeaveRequest::orderBy('id')->get()->all();
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin);

        Livewire::test(ListLeaveRequests::class)
            ->mountTableAction('view', $theirs)
            ->assertMountedActionModalSee([
                'Seminar nasional', 'Rp20.000', // Perkiraan: 2 hari kerja × Rp10.000.
                'Izin bersama', $a->name, 'Pengaju', 'Lampiran pengajuan ini', 'Setujui', 'Tolak',
            ]);

        // Setujui langsung dari detail.
        Livewire::test(ListLeaveRequests::class)
            ->mountTableAction(['view', 'approveFromDetail'], $theirs)
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame(LeaveStatus::Approved, $theirs->fresh()->status);
        $this->assertSame(LeaveStatus::Pending, $mine->fresh()->status);
        $this->assertSame(['amount' => 20000, 'final' => true], $theirs->fresh()->load('attendances')->deductionSummary());
    }

    public function test_leave_list_api_has_detail_fields(): void
    {
        $this->amounts(['tugas_luar' => 10000]);
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $this->submit($a, ['member_ids' => [$b->id], 'attachment' => $this->image(5)])->assertCreated();
        LeaveRequest::where('employee_id', $b->id)->sole()->update(['status' => LeaveStatus::Rejected, 'review_note' => 'Tidak ikut']);

        Sanctum::actingAs($a->user);
        $this->withHeader('X-Device-UID', $this->deviceUids[$a->id])->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('data.0.deduction', ['amount' => 20000, 'final' => false])
            ->assertJsonPath('data.0.attachment_is_image', true)
            ->assertJsonPath('data.0.attachment_url', fn ($url) => is_string($url) && $url !== '')
            ->assertJsonPath('data.0.group.0.name', $b->name)
            ->assertJsonPath('data.0.group.0.status', 'rejected')
            ->assertJsonPath('data.0.group.0.status_label', 'Ditolak')
            ->assertJsonPath('data.0.group.0.unit_name', 'Biro SDM');

        Sanctum::actingAs($b->user);
        $this->withHeader('X-Device-UID', $this->deviceUids[$b->id])->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('data.0.review_note', 'Tidak ikut')
            ->assertJsonPath('data.0.deduction', ['amount' => 0, 'final' => true]);
    }

    public function test_perceptual_hash_distance(): void
    {
        $this->assertSame(0, AttachmentFingerprint::distance('ffffffffffffffff', 'ffffffffffffffff'));
        $this->assertSame(64, AttachmentFingerprint::distance('ffffffffffffffff', '0000000000000000'));
        $this->assertSame(2, AttachmentFingerprint::distance('8000000000000001', '0000000000000000'));
        $this->assertNull(AttachmentFingerprint::perceptual('%PDF-1.4 bukan gambar'));
    }
}
