<?php

namespace Tests\Feature;

use App\Enums\CalendarEventType;
use App\Enums\ScheduleScope;
use App\Enums\UnitType;
use App\Enums\WorkPatternType;
use App\Models\CalendarEvent;
use App\Models\ScheduleOverride;
use App\Models\Unit;
use App\Services\ShiftResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCampus;
use Tests\TestCase;

class ShiftResolverTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private ShiftResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampus();
        $this->resolver = app(ShiftResolver::class);
    }

    public function test_unit_pattern_then_parent_then_institution_default(): void
    {
        $fakultas = Unit::create(['institution_id' => $this->inst->id, 'name' => 'FT', 'type' => UnitType::Fakultas, 'work_pattern_id' => $this->tendikPattern->id]);
        $prodi = Unit::create(['institution_id' => $this->inst->id, 'parent_id' => $fakultas->id, 'name' => 'IF', 'type' => UnitType::Prodi]);
        $e = $this->makeEmployee(['unit_id' => $prodi->id]);

        $s = $this->resolver->resolve($e, '2026-10-28');
        $this->assertSame('unit_pattern', $s->source);
        $this->assertSame('07:30', $s->startTime);

        $fakultas->update(['work_pattern_id' => null]);
        $this->assertTrue($this->resolver->resolve($e->fresh(), '2026-10-28')->isDayOff);

        $this->inst->update(['default_work_pattern_id' => $this->tendikPattern->id]);
        $this->assertSame('institution_pattern', $this->resolver->resolve($e->fresh(), '2026-10-28')->source);
    }

    public function test_employee_pattern_beats_unit_pattern(): void
    {
        $flex = $this->makePattern('Dosen Fleksibel', WorkPatternType::Flexible, array_fill(1, 5, ['06:00', '21:00']), minDaily: 360);
        $e = $this->makeEmployee(['work_pattern_id' => $flex->id]);

        $s = $this->resolver->resolve($e, '2026-10-28');
        $this->assertSame('employee_pattern', $s->source);
        $this->assertTrue($s->isFlexible());
    }

    public function test_calendar_holiday_beats_weekly_pattern(): void
    {
        CalendarEvent::create(['institution_id' => $this->inst->id, 'date' => '2026-10-28', 'name' => 'Libur Dies Natalis', 'type' => CalendarEventType::LiburKampus]);
        $s = $this->resolver->resolve($this->makeEmployee(), '2026-10-28');

        $this->assertTrue($s->isDayOff);
        $this->assertSame('Libur Dies Natalis', $s->reason);
    }

    public function test_group_hours_override_applies_but_does_not_erase_holiday(): void
    {
        $ramadan = $this->makePattern('Jam Ramadan', WorkPatternType::Fixed, array_fill(1, 5, ['08:00', '14:00']));
        ScheduleOverride::create([
            'institution_id' => $this->inst->id, 'scope_type' => ScheduleScope::Institution, 'scope_id' => null,
            'start_date' => '2026-10-26', 'end_date' => '2026-10-30', 'work_pattern_id' => $ramadan->id, 'reason' => 'Jam kerja Ramadan',
        ]);
        $e = $this->makeEmployee();

        $s = $this->resolver->resolve($e, '2026-10-27');
        $this->assertSame('override:institution', $s->source);
        $this->assertSame('08:00', $s->startTime);
        $this->assertSame('Jam kerja Ramadan', $s->reason);

        CalendarEvent::create(['institution_id' => $this->inst->id, 'date' => '2026-10-28', 'name' => 'Libur nasional', 'type' => CalendarEventType::LiburNasional]);
        $this->assertTrue($this->resolver->resolve($e, '2026-10-28')->isDayOff);
    }

    public function test_employee_override_beats_everything(): void
    {
        $sabtu = $this->makePattern('Tugas Sabtu', WorkPatternType::Fixed, [6 => ['08:00', '12:00']]);
        $e = $this->makeEmployee();
        ScheduleOverride::create([
            'institution_id' => $this->inst->id, 'scope_type' => ScheduleScope::Unit, 'scope_id' => $this->unit->id,
            'start_date' => '2026-10-31', 'end_date' => '2026-10-31', 'is_day_off' => true, 'reason' => 'Libur unit',
        ]);
        ScheduleOverride::create([
            'institution_id' => $this->inst->id, 'scope_type' => ScheduleScope::Employee, 'scope_id' => $e->id,
            'start_date' => '2026-10-31', 'end_date' => '2026-10-31', 'work_pattern_id' => $sabtu->id, 'reason' => 'Menguji sidang skripsi',
        ]);

        $s = $this->resolver->resolve($e, '2026-10-31');
        $this->assertSame('override:employee', $s->source);
        $this->assertFalse($s->isDayOff);
        $this->assertSame('12:00', $s->endTime);
    }
}
