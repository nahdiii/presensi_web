<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToInstitution;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis izin (master data, Pengaturan → Jenis Izin): Izin, Sakit, Cuti, Tugas
 * luar, dan jenis tambahan yang dibuat Admin SDM.
 *
 * deduction_per_day disalin ke pengajuan saat diajukan; mengubahnya di sini
 * hanya berlaku untuk pengajuan baru. Kode tidak berubah setelah dibuat.
 */
#[Guarded(['id'])]
class LeaveType extends Model implements HasLabel
{
    use BelongsToInstitution;

    /** Kode cuti tahunan: jatahnya bisa diganti per pegawai di form Pegawai. */
    public const ANNUAL_LEAVE = 'cuti';

    protected function casts(): array
    {
        return [
            'attendance_status' => AttendanceStatus::class,
            'deduction_per_day' => 'integer',
            'annual_quota_days' => 'integer',
            'allows_group' => 'boolean',
            'requires_attachment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Jenis baru tanpa urutan ditaruh paling bawah.
        static::creating(function (LeaveType $t) {
            $t->sort ??= (int) self::where('institution_id', $t->institution_id)->max('sort') + 1;
        });
    }

    /** Jenis bawaan untuk institusi baru. */
    public static function defaults(): array
    {
        return [
            ['code' => 'izin', 'name' => 'Izin', 'attendance_status' => AttendanceStatus::Leave, 'allows_group' => true],
            ['code' => 'sakit', 'name' => 'Sakit', 'attendance_status' => AttendanceStatus::Sick],
            ['code' => self::ANNUAL_LEAVE, 'name' => 'Cuti', 'attendance_status' => AttendanceStatus::Leave,
                'annual_quota_days' => (int) config('attendance.leave.annual_quota_days')],
            ['code' => 'tugas_luar', 'name' => 'Tugas luar', 'attendance_status' => AttendanceStatus::Duty, 'allows_group' => true],
        ];
    }

    public static function createDefaultsFor(Institution $institution): void
    {
        foreach (self::defaults() as $i => $type) {
            self::firstOrCreate(['institution_id' => $institution->id, 'code' => $type['code']], $type + ['sort' => $i + 1]);
        }
    }

    /** Status presensi yang dapat dipakai jenis izin. */
    public static function attendanceStatusOptions(): array
    {
        return collect([AttendanceStatus::Leave, AttendanceStatus::Sick, AttendanceStatus::Duty])
            ->mapWithKeys(fn (AttendanceStatus $s) => [$s->value => $s->getLabel()])->all();
    }

    public static function findCode(int $institutionId, string $code): ?self
    {
        return self::where('institution_id', $institutionId)->where('code', $code)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function attendanceStatus(): AttendanceStatus
    {
        return $this->attendance_status;
    }

    public function allowsGroup(): bool
    {
        return $this->allows_group;
    }

    public function hasQuota(): bool
    {
        return $this->annual_quota_days !== null || $this->code === self::ANNUAL_LEAVE;
    }

    /** Jatah per tahun untuk pegawai ini; cuti tahunan memakai jatah pegawai bila diisi. */
    public function quotaFor(Employee $employee): ?int
    {
        if ($this->code === self::ANNUAL_LEAVE) {
            return $employee->annual_leave_quota ?? $this->annual_quota_days ?? config('attendance.leave.annual_quota_days');
        }

        return $this->annual_quota_days;
    }
}
