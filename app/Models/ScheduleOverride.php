<?php

namespace App\Models;

use App\Enums\ScheduleScope;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengecualian jadwal per tanggal: jam Ramadan, libur semester, tugas Sabtu. */
#[Guarded(['id'])]
class ScheduleOverride extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'scope_type' => ScheduleScope::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_day_off' => 'boolean',
        ];
    }

    public function workPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class);
    }
}
