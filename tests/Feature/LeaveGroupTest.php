<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\UnitType;
use App\Filament\Resources\DeductionRuleSets\Pages\ManageDeductionRuleSets;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Jobs\ProcessAttendancePhoto;
use App\Models\Attendance;
use App\Models\DeductionRuleSet;
use App\Models\Employee;
use App\Models\LeaveRequest;
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

    private function rules(array $leaveAmounts): DeductionRuleSet
    {
        return DeductionRuleSet::create(['leave_amounts' => $leaveAmounts + DeductionRuleSet::defaults()['leave_amounts']]
            + DeductionRuleSet::defaults() + ['institution_id' => $this->inst->id, 'effective_from' => '2000-01-01']);
    }

    private function submit(Employee $e, array $data)
    {
        Sanctum::actingAs($e->user);

        return $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])
            ->post('/api/v1/leaves', $data + ['type' => 'tugas_luar', 'reason' => 'Seminar nasional',
                'start_date' => '2026-11-02', 'end_date' => '2026-11-03'], ['Accept' => 'application/json']);
    }

    private function leave(Employee $e, LeaveType $type, string $date = '2026-10-28'): LeaveRequest
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
        $this->rules(['izin' => 25000, 'sakit' => 10000, 'cuti' => 0]);
        $izin = $this->makeEmployee();
        $cuti = $this->makeEmployee();
        $sakit = $this->makeEmployee();
        $svc = app(LeaveService::class);
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);

        // Izin dan Cuti sama-sama berstatus "izin" di presensi, tetapi potongannya beda.
        $svc->approve($this->leave($izin, LeaveType::Izin), $admin);
        $svc->approve($this->leave($cuti, LeaveType::Cuti), $admin);
        $this->assertSame(AttendanceStatus::Leave, $this->row($cuti)->status);
        $this->assertSame(25000, $this->row($izin)->deduction_amount);
        $this->assertSame(['Izin'], $this->row($izin)->deductionCodes());
        $this->assertSame(0, $this->row($cuti)->deduction_amount);
        $this->assertSame([], $this->row($cuti)->deductionCodes());

        // Disetujui sebelum hari H: baris dibuat saat tutup hari, tetap memakai jenisnya.
        $leave = $this->leave($sakit, LeaveType::Sakit, '2026-10-29');
        $svc->approve($leave, $admin);
        $this->at('2026-10-29 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame($leave->id, $this->row($sakit, '2026-10-29')->leave_request_id);
        $this->assertSame(10000, $this->row($sakit, '2026-10-29')->deduction_amount);
    }

    public function test_leave_deduction_is_capped_and_follows_new_rules(): void
    {
        $this->rules(['izin' => 80000]); // Lebih besar dari alpa Rp50.000.
        $e = $this->makeEmployee();
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        app(LeaveService::class)->approve($this->leave($e, LeaveType::Izin), $admin);
        $this->assertSame(50000, $this->row($e)->deduction_amount);

        DeductionRuleSet::create(['leave_amounts' => ['izin' => 15000]] + DeductionRuleSet::defaults()
            + ['institution_id' => $this->inst->id, 'effective_from' => '2026-10-01']);
        app(RecalculationService::class)->run($this->inst->id, '2026-10-01', '2026-10-28');
        $this->assertSame(15000, $this->row($e)->deduction_amount);
    }

    public function test_admin_sets_leave_amounts_in_rule_form(): void
    {
        $admin = User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']);
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin);

        Livewire::test(ManageDeductionRuleSets::class)
            ->callAction('create', ['effective_from' => '2026-11-01', 'leave_amounts' => ['izin' => 25000, 'sakit' => 0, 'cuti' => 0, 'tugas_luar' => 0]])
            ->assertHasNoActionErrors();

        $rules = DeductionRuleSet::sole();
        $this->assertSame(25000, $rules->leaveAmount(LeaveType::Izin));
        $this->assertSame(0, $rules->leaveAmount(LeaveType::Cuti));
        $this->assertStringContainsString('Izin Rp25.000/hari', $rules->summary());
    }

    public function test_api_reports_leave_deductions_and_codes(): void
    {
        $this->rules(['izin' => 25000]);
        $e = $this->makeEmployee();
        Sanctum::actingAs($e->user);

        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('meta.leave_deductions.izin', 25000)
            ->assertJsonPath('meta.leave_deductions.cuti', 0)
            ->assertJsonPath('meta.group_types', ['izin', 'tugas_luar']);

        app(LeaveService::class)->approve($this->leave($e, LeaveType::Izin),
            User::create(['institution_id' => $this->inst->id, 'name' => 'Admin', 'email' => 'a@kampus.test', 'password' => 'x']));
        $this->withHeader('X-Device-UID', $this->deviceUids[$e->id])->getJson('/api/v1/attendance/history?month=2026-10')
            ->assertOk()->assertJsonPath('data.items.0.deduction_label', 'Izin')->assertJsonPath('data.items.0.deduction_amount', 25000);
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
        $this->leave($b, LeaveType::Izin, '2026-11-03');

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

    public function test_perceptual_hash_distance(): void
    {
        $this->assertSame(0, AttachmentFingerprint::distance('ffffffffffffffff', 'ffffffffffffffff'));
        $this->assertSame(64, AttachmentFingerprint::distance('ffffffffffffffff', '0000000000000000'));
        $this->assertSame(2, AttachmentFingerprint::distance('8000000000000001', '0000000000000000'));
        $this->assertNull(AttachmentFingerprint::perceptual('%PDF-1.4 bukan gambar'));
    }
}
