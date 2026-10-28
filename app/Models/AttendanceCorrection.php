<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengajuan koreksi lupa absen; diterapkan ke attendances saat disetujui. */
#[Guarded(['id'])]
class AttendanceCorrection extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => LeaveStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
