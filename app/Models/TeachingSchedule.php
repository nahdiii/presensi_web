<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jadwal mengajar mingguan seorang dosen selama satu semester. */
#[Guarded(['id'])]
class TeachingSchedule extends Model
{
    use BelongsToInstitution;

    public const WEEKDAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TeachingSession::class);
    }

    /** Jadwal aktif yang jatuh pada tanggal ini (hari dan rentang semester cocok). */
    public function scopeOccursOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->where('weekday', CarbonImmutable::parse($date)->isoWeekday())
            ->whereDate('valid_from', '<=', $date)
            ->whereDate('valid_until', '>=', $date);
    }

    public function startAt(string $date, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse("$date {$this->start_time}", $tz);
    }

    public function endAt(string $date, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse("$date {$this->end_time}", $tz);
    }

    /** "Pemrograman Web (B)" */
    public function getLabelAttribute(): string
    {
        return "{$this->course->name} ({$this->class_name})";
    }
}
