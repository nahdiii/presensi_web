<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\WorkPatternType;
use App\Filament\Resources\WorkPatterns\Pages\EditWorkPattern;
use App\Jobs\ProcessAttendancePhoto;
use App\Models\Attendance;
use App\Models\DeductionRuleSet;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkPattern;
use App\Services\RecalculationService;
use App\Services\ShiftResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsCampus;
use Tests\TestCase;

/**
 * Potongan terlambat (TL) dan pulang sebelum waktu (PSW), serta jadwal
 * bertanggal berlaku. Jadwal uji: Senin–Jumat 07.30–16.00, toleransi 15 menit.
 * 28 Oktober 2026 hari Rabu.
 */
class DeductionTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attendance');
        Queue::fake([ProcessAttendancePhoto::class]);
        $this->buildCampus();
        $this->tendikPattern->recordVersion('2000-01-01', 'Jadwal awal');
        DeductionRuleSet::create(DeductionRuleSet::defaults() + ['institution_id' => $this->inst->id, 'effective_from' => '2000-01-01']);
    }

    private function row(Employee $e, string $date = '2026-10-28'): Attendance
    {
        return Attendance::where('employee_id', $e->id)->whereDate('date', $date)->sole();
    }

    /** Mengubah jam masuk/pulang Senin–Jumat lewat form, lalu mencatatnya sebagai versi. */
    private function changeHours(WorkPattern $p, string $start, string $end, string $from): void
    {
        $p->days()->where('weekday', '<=', 5)->update(['start_time' => $start, 'end_time' => $end]);
        $p->recordVersion($from, "Jam baru {$start}–{$end}");
    }

    public function test_late_and_early_leave_minutes_map_to_tiers(): void
    {
        $this->at('2026-10-28 06:00');
        $e = $this->makeEmployee();

        $this->at('2026-10-28 08:10'); // 40 menit dari jam masuk → TL2
        $this->punch($e)->assertCreated();
        $a = $this->row($e);
        $this->assertSame(40, $a->late_minutes);
        $this->assertSame('TL2', $a->late_code);
        $this->assertEquals(1.0, $a->deduction_percent);

        $this->at('2026-10-28 15:35'); // 25 menit sebelum jam pulang → PSW1
        $this->punch($e, 'out')->assertOk();
        $a->refresh();
        $this->assertTrue($a->is_early_leave);
        $this->assertSame(25, $a->early_leave_minutes);
        $this->assertSame('PSW1', $a->early_leave_code);
        $this->assertEquals(1.5, $a->deduction_percent);
    }

    public function test_arriving_within_tolerance_and_leaving_on_time_has_no_deduction(): void
    {
        $this->at('2026-10-28 06:00');
        $e = $this->makeEmployee();
        $this->at('2026-10-28 07:44');
        $this->punch($e)->assertCreated();
        $this->at('2026-10-28 16:00');
        $this->punch($e, 'out')->assertOk();

        $a = $this->row($e);
        $this->assertSame(AttendanceStatus::Present, $a->status);
        $this->assertNull($a->late_code);
        $this->assertNull($a->early_leave_code);
        $this->assertEquals(0, $a->deduction_percent);
    }

    public function test_no_checkout_absent_and_leave(): void
    {
        $this->at('2026-10-28 06:00');
        $noCheckout = $this->makeEmployee();
        $absent = $this->makeEmployee();
        $sick = $this->makeEmployee();
        LeaveRequest::create([
            'institution_id' => $this->inst->id, 'employee_id' => $sick->id, 'type' => LeaveType::Sakit,
            'start_date' => '2026-10-28', 'end_date' => '2026-10-28', 'reason' => 'Demam', 'status' => LeaveStatus::Approved,
        ]);
        $this->at('2026-10-28 07:50'); // 20 menit → TL1
        $this->punch($noCheckout)->assertCreated();

        $this->at('2026-10-28 23:50');
        $this->artisan('attendance:close-day')->assertSuccessful();

        $a = $this->row($noCheckout);
        $this->assertSame(AttendanceStatus::Incomplete, $a->status);
        $this->assertSame('TL1', $a->late_code);
        $this->assertSame(DeductionRuleSet::NO_CHECKOUT_CODE, $a->early_leave_code);
        $this->assertEquals(2.0, $a->deduction_percent); // 0,5 + 1,5
        $this->assertEquals(5.0, $this->row($absent)->deduction_percent);
        $this->assertEquals(0, $this->row($sick)->deduction_percent);
    }

    public function test_daily_deduction_is_capped_at_absent_deduction(): void
    {
        DeductionRuleSet::create([
            'institution_id' => $this->inst->id, 'effective_from' => '2026-10-01', 'absent_percent' => 2,
        ] + DeductionRuleSet::defaults());
        $this->at('2026-10-28 06:00');
        $e = $this->makeEmployee();
        $this->at('2026-10-28 09:30'); // TL4 1,5%
        $this->punch($e)->assertCreated();
        $this->at('2026-10-28 14:00'); // PSW4 1,5%
        $this->punch($e, 'out')->assertOk();

        $this->assertEquals(2.0, $this->row($e)->deduction_percent);
    }

    public function test_rules_apply_by_attendance_date(): void
    {
        DeductionRuleSet::create([
            'institution_id' => $this->inst->id, 'effective_from' => '2026-10-29',
            'late_tiers' => [['code' => 'TL1', 'from' => 1, 'to' => null, 'percent' => 3]],
        ] + DeductionRuleSet::defaults());
        $this->at('2026-10-28 06:00');
        $e = $this->makeEmployee();
        foreach (['2026-10-28', '2026-10-29'] as $date) {
            $this->at("$date 07:50");
            $this->punch($e)->assertCreated();
        }

        $this->assertEquals(0.5, $this->row($e, '2026-10-28')->deduction_percent); // aturan lama
        $this->assertEquals(3.0, $this->row($e, '2026-10-29')->deduction_percent); // aturan baru
    }

    public function test_future_dated_hours_change_does_not_affect_today(): void
    {
        $this->at('2026-10-28 06:00');
        $e = $this->makeEmployee();
        $this->changeHours($this->tendikPattern, '08:00', '16:30', '2026-10-29');

        $this->at('2026-10-28 07:50'); // masih jam lama 07.30 → terlambat 20 menit
        $this->punch($e)->assertCreated();
        $this->assertSame('TL1', $this->row($e)->late_code);

        $this->at('2026-10-29 07:50'); // jam baru 08.00 → tepat waktu
        $this->punch($e)->assertCreated();
        $a = $this->row($e, '2026-10-29');
        $this->assertSame(AttendanceStatus::Present, $a->status);
        $this->assertSame(0, $a->late_minutes);
        $this->assertSame('2026-10-29', $a->schedule_snapshot['effective_from']);
    }

    public function test_backdated_hours_change_recalculates_lateness_and_deduction(): void
    {
        $this->at('2026-10-27 06:00');
        $e = $this->makeEmployee();
        foreach (['2026-10-27', '2026-10-28'] as $date) {
            $this->at("$date 08:10");
            $this->punch($e)->assertCreated();
            $this->at("$date 16:30");
            $this->punch($e, 'out')->assertOk();
        }
        $this->assertSame('TL2', $this->row($e)->late_code);

        // Admin baru memasukkan jam baru 08.00–16.30 yang sebenarnya berlaku sejak 28 Oktober.
        $this->changeHours($this->tendikPattern, '08:00', '16:30', '2026-10-28');
        app(RecalculationService::class)->run($this->inst->id, '2026-10-28', '2026-10-28');

        $a = $this->row($e);
        $this->assertSame(AttendanceStatus::Present, $a->status); // 10 menit, masih dalam toleransi
        $this->assertSame(0, $a->late_minutes);
        $this->assertSame(0, $a->early_leave_minutes);
        $this->assertEquals(0, $a->deduction_percent);
        $this->assertSame('08:00', $a->schedule_snapshot['start_time']);
        // Hari sebelum tanggal berlaku tetap memakai jam lama.
        $this->assertSame('TL2', $this->row($e, '2026-10-27')->late_code);
    }

    public function test_editing_hours_in_admin_records_history_and_recalculates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@kampus.test')->sole();
        $pattern = WorkPattern::where('institution_id', $admin->institution_id)->where('name', 'Tendik Reguler')->sole();
        $tendik = Employee::whereHas('user', fn ($q) => $q->where('email', 'tendik@kampus.test'))->sole();

        $this->at('2026-10-28 08:10');
        Attendance::create([
            'institution_id' => $tendik->institution_id, 'employee_id' => $tendik->id, 'date' => '2026-10-28',
            'status' => AttendanceStatus::Late, 'late_minutes' => 40, 'check_in_at' => now(),
            'schedule_snapshot' => app(ShiftResolver::class)->resolve($tendik, '2026-10-28')->toArray(),
        ]);

        $this->actingAs($admin);
        $component = Livewire::test(EditWorkPattern::class, ['record' => $pattern->getRouteKey()]);
        $days = collect($component->get('data.days'))
            ->map(fn ($d) => $d['weekday'] <= 5 ? ['start_time' => '08:00'] + $d : $d)->all();
        $component->fillForm(['effective_from' => '2026-10-28', 'change_note' => 'SK Rektor 12/2026', 'days' => $days])
            ->call('save')->assertHasNoFormErrors();

        $versions = $pattern->versions()->get();
        $this->assertCount(2, $versions);
        $this->assertSame('2026-10-28', $versions->last()->effective_from->toDateString());
        $this->assertSame('SK Rektor 12/2026', $versions->last()->note);
        $this->assertSame($admin->id, $versions->last()->created_by);

        $a = $this->row($tendik);
        $this->assertSame(AttendanceStatus::Present, $a->status);
        $this->assertEquals(0, $a->deduction_percent);
        $this->assertSame(1, $admin->notifications()->count()); // kabar hitung ulang selesai

        // Simpan lagi tanpa mengubah jam: tidak menambah riwayat.
        Livewire::test(EditWorkPattern::class, ['record' => $pattern->getRouteKey()])
            ->fillForm(['effective_from' => '2026-10-28', 'name' => 'Tendik Reguler (2026)'])->call('save')->assertHasNoFormErrors();
        $this->assertCount(2, $pattern->versions()->get());
    }

    public function test_campus_pattern_applies_when_unit_has_none(): void
    {
        $kampus2 = $this->makeOffice('Kampus 2', self::LAT + 0.05, self::LNG, 100);
        $siang = $this->makePattern('Kampus 2', WorkPatternType::Fixed, array_fill(1, 5, ['08:30', '17:00']), tolerance: 0);
        $siang->recordVersion('2000-01-01');
        $kampus2->update(['work_pattern_id' => $siang->id]);
        $this->unit->update(['work_pattern_id' => null]);
        $e = $this->makeEmployee(offices: [$kampus2]);

        $s = app(ShiftResolver::class)->resolve($e->fresh(), '2026-10-28');
        $this->assertSame('office_pattern', $s->source);
        $this->assertSame('08:30', $s->startTime);

        // Pola unit tetap lebih diutamakan daripada pola kampus.
        $this->unit->update(['work_pattern_id' => $this->tendikPattern->id]);
        $this->assertSame('07:30', app(ShiftResolver::class)->resolve($e->fresh(), '2026-10-28')->startTime);
    }
}
