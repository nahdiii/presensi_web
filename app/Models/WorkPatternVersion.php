<?php

namespace App\Models;

use App\Enums\WorkPatternType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jam kerja sebuah pola yang berlaku mulai effective_from sampai versi
 * berikutnya. Tidak pernah diubah setelah dibuat: perubahan jam selalu
 * menjadi versi baru, sehingga riwayatnya utuh.
 */
#[Guarded(['id'])]
class WorkPatternVersion extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'type' => WorkPatternType::class,
            'days' => 'array',
        ];
    }

    public function workPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array{start_time: ?string, end_time: ?string, break_start: ?string, break_end: ?string, is_day_off: bool}|null */
    public function dayFor(int $isoWeekday): ?array
    {
        return $this->days[$isoWeekday] ?? $this->days[(string) $isoWeekday] ?? null;
    }

    /** Isi jadwal yang dibandingkan untuk menentukan ada perubahan atau tidak. */
    public function scheduleAttributes(): array
    {
        return [
            'type' => $this->type instanceof WorkPatternType ? $this->type->value : $this->type,
            'late_tolerance_min' => (int) $this->late_tolerance_min,
            'checkin_open_min' => (int) $this->checkin_open_min,
            'min_daily_minutes' => $this->min_daily_minutes === null ? null : (int) $this->min_daily_minutes,
            'days' => collect($this->days)->mapWithKeys(fn ($d, $wd) => [(int) $wd => $d])->sortKeys()->all(),
        ];
    }

    public function summary(): string
    {
        return collect($this->days)->sortKeys()
            ->reject(fn ($d) => $d['is_day_off'] ?? false)
            ->map(fn ($d, $wd) => mb_substr(WorkPatternDay::WEEKDAYS[$wd], 0, 3).' '.$d['start_time'].'–'.$d['end_time'])
            ->implode(' · ');
    }
}
