<?php

namespace Tests\Concerns;

use App\Enums\AttendanceType;
use App\Enums\EmployeeType;
use App\Enums\UnitType;
use App\Enums\WorkPatternType;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Office;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkPattern;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Kampus kecil untuk test: satu institusi (WITA), Kampus 1 radius 100 m,
 * pola "Tendik Reguler" Senin–Jumat 07.30–16.00 toleransi 15 menit.
 */
trait BuildsCampus
{
    protected Institution $inst;

    protected Office $kampus1;

    protected WorkPattern $tendikPattern;

    protected Unit $unit;

    /** @var array<int, string> device_uid aktif per employee_id */
    protected array $deviceUids = [];

    protected const LAT = -3.3152;

    protected const LNG = 114.5883;

    protected function buildCampus(): void
    {
        $this->inst = Institution::create(['name' => 'Kampus Uji', 'timezone' => 'Asia/Makassar']);
        $this->tendikPattern = $this->makePattern('Tendik Reguler', WorkPatternType::Fixed,
            array_fill(1, 5, ['07:30', '16:00', '12:00', '13:00']), tolerance: 15, open: 60);
        $this->kampus1 = $this->makeOffice('Kampus 1', self::LAT, self::LNG, 100);
        $this->unit = Unit::create(['institution_id' => $this->inst->id, 'name' => 'Biro SDM', 'type' => UnitType::Biro, 'work_pattern_id' => $this->tendikPattern->id]);
    }

    protected function makePattern(string $name, WorkPatternType $type, array $days, int $tolerance = 0, int $open = 60, ?int $minDaily = null): WorkPattern
    {
        $p = WorkPattern::create([
            'institution_id' => $this->inst->id, 'name' => $name, 'type' => $type,
            'late_tolerance_min' => $tolerance, 'checkin_open_min' => $open, 'min_daily_minutes' => $minDaily,
        ]);
        foreach (range(1, 7) as $wd) {
            $d = $days[$wd] ?? null;
            $p->days()->create([
                'weekday' => $wd, 'start_time' => $d[0] ?? null, 'end_time' => $d[1] ?? null,
                'break_start' => $d[2] ?? null, 'break_end' => $d[3] ?? null, 'is_day_off' => $d === null,
            ]);
        }

        return $p;
    }

    protected function makeOffice(string $name, float $lat, float $lng, int $radius): Office
    {
        return Office::create([
            'institution_id' => $this->inst->id, 'name' => $name, 'latitude' => $lat, 'longitude' => $lng,
            'radius_m' => $radius, 'timezone' => 'Asia/Makassar',
        ]);
    }

    /** Pegawai dengan perangkat terikat; device_uid-nya ada di \$deviceUids. */
    protected function makeEmployee(array $attrs = [], ?array $offices = null): Employee
    {
        $user = User::create(['institution_id' => $this->inst->id, 'name' => 'Pegawai '.Str::random(4),
            'email' => Str::random(8).'@kampus.test', 'password' => 'password']);
        $e = Employee::create($attrs + [
            'institution_id' => $this->inst->id, 'user_id' => $user->id, 'nip' => (string) random_int(1e9, 9e9),
            'employee_type' => EmployeeType::Tendik, 'attendance_type' => AttendanceType::Fixed, 'unit_id' => $this->unit->id,
        ]);
        $e->offices()->attach(collect($offices ?? [$this->kampus1])->pluck('id'));
        $uid = Str::random(32);
        $e->devices()->create(['device_uid' => $uid, 'model' => 'Test Phone', 'platform' => 'android', 'bound_at' => now()]);
        $this->deviceUids[$e->id] = $uid;

        return $e;
    }

    /** Jam server dalam WITA. */
    protected function at(string $datetime): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($datetime, 'Asia/Makassar'));
    }

    protected function punch(Employee $e, string $kind = 'in', array $overrides = [], ?string $deviceUid = null)
    {
        Sanctum::actingAs($e->user);
        $payload = $overrides + [
            'latitude' => self::LAT + 0.0002, // ±22 m dari titik kampus
            'longitude' => self::LNG,
            'accuracy' => 12,
            'is_mocked' => 0,
            'is_rooted' => 0,
            'face_count' => 1,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ];

        return $this->withHeader('X-Device-UID', $deviceUid ?? $this->deviceUids[$e->id])
            ->post('/api/v1/attendance/check-'.$kind, $payload, ['Accept' => 'application/json']);
    }
}
