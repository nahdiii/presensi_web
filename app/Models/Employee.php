<?php

namespace App\Models;

use App\Enums\AttendanceType;
use App\Enums\EmployeeType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Pegawai kampus: dosen atau tenaga kependidikan. */
#[Guarded(['id'])]
class Employee extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'employee_type' => EmployeeType::class,
            'attendance_type' => AttendanceType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function workPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class);
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class, 'employee_offices');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function activeDevice(): HasOne
    {
        return $this->hasOne(Device::class)->whereNull('revoked_at')->latestOfMany('bound_at');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function exitPermits(): HasMany
    {
        return $this->hasMany(ExitPermit::class);
    }

    public function presenceChecks(): HasMany
    {
        return $this->hasMany(PresenceCheck::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function getNameAttribute(): ?string
    {
        return $this->user?->name;
    }

    /**
     * Cabut perangkat aktif dan semua token API, sehingga pegawai bisa
     * login dan mengikat HP baru.
     */
    public function resetDevice(): void
    {
        $this->devices()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->user->tokens()->delete();
    }
}
