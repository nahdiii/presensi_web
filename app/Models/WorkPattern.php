<?php

namespace App\Models;

use App\Enums\WorkPatternType;
use App\Models\Concerns\BelongsToInstitution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pola jadwal mingguan. Kolom di tabel ini dan work_pattern_days hanyalah
 * isian form terakhir; jam yang dipakai untuk menghitung presensi selalu
 * diambil dari versi yang berlaku pada tanggalnya (lihat versionFor).
 */
#[Guarded(['id'])]
class WorkPattern extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['type' => WorkPatternType::class];
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkPatternDay::class)->orderBy('weekday');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkPatternVersion::class)->orderBy('effective_from')->orderBy('id');
    }

    public function dayFor(int $isoWeekday): ?WorkPatternDay
    {
        return $this->days->firstWhere('weekday', $isoWeekday);
    }

    /**
     * Versi yang berlaku pada $date: tanggal berlaku terbaru yang tidak
     * melewati $date; bila dua versi bertanggal sama, yang dicatat terakhir.
     * Tanggal sebelum versi pertama memakai versi pertama.
     */
    public function versionFor(CarbonImmutable|string $date): WorkPatternVersion
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $versions = $this->versions;

        return $versions->last(fn (WorkPatternVersion $v) => $v->effective_from->toDateString() <= $date)
            ?? $versions->first()
            ?? $this->draftVersion();
    }

    /** Versi dari isian form saat ini (belum disimpan). */
    public function draftVersion(): WorkPatternVersion
    {
        $hm = fn (?string $t) => $t ? substr($t, 0, 5) : null;

        return new WorkPatternVersion([
            'work_pattern_id' => $this->id,
            'type' => $this->type,
            'late_tolerance_min' => (int) $this->late_tolerance_min,
            'checkin_open_min' => (int) $this->checkin_open_min,
            'min_daily_minutes' => $this->min_daily_minutes,
            'days' => $this->days->mapWithKeys(fn (WorkPatternDay $d) => [$d->weekday => [
                'start_time' => $hm($d->start_time),
                'end_time' => $hm($d->end_time),
                'break_start' => $hm($d->break_start),
                'break_end' => $hm($d->break_end),
                'is_day_off' => (bool) $d->is_day_off,
            ]])->all(),
        ]);
    }

    /**
     * Mencatat isian form saat ini sebagai versi yang berlaku mulai $from.
     * Tidak membuat versi bila jamnya sama dengan yang sudah berlaku pada
     * tanggal itu (mis. hanya nama pola yang diganti).
     */
    public function recordVersion(string $from, ?string $note = null, ?int $userId = null): ?WorkPatternVersion
    {
        $this->load('days', 'versions');
        $draft = $this->draftVersion();
        if ($this->versions->isNotEmpty() && $this->versionFor($from)->scheduleAttributes() === $draft->scheduleAttributes()) {
            return null;
        }
        $draft->fill(['effective_from' => $from, 'note' => $note, 'created_by' => $userId])->save();
        $this->unsetRelation('versions');

        return $draft;
    }
}
