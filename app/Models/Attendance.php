<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris per pegawai per hari. */
#[Guarded(['id'])]
class Attendance extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
            'schedule_snapshot' => 'array',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_lat' => 'float',
            'check_in_lng' => 'float',
            'check_out_lat' => 'float',
            'check_out_lng' => 'float',
            'check_in_distance_m' => 'float',
            'check_out_distance_m' => 'float',
            'check_in_accuracy_m' => 'float',
            'check_out_accuracy_m' => 'float',
            'is_early_leave' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function checkInOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'check_in_office_id');
    }

    public function checkOutOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'check_out_office_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
