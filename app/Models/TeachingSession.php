<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Presensi satu sesi kuliah; satu baris per jadwal per tanggal. */
#[Guarded(['id'])]
class TeachingSession extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
            'checked_in_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_m' => 'float',
            'distance_m' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TeachingSchedule::class, 'teaching_schedule_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
