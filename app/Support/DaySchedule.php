<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Jadwal yang berlaku untuk satu pegawai pada satu tanggal, hasil
 * ShiftResolver. Disimpan apa adanya di attendances.schedule_snapshot
 * sehingga rekap lama tidak berubah ketika pola jadwal diedit.
 */
final readonly class DaySchedule
{
    public function __construct(
        public string $source,
        public bool $isDayOff,
        public string $type = 'fixed',
        public ?int $workPatternId = null,
        public ?string $patternName = null,
        public ?string $startTime = null,
        public ?string $endTime = null,
        public ?string $breakStart = null,
        public ?string $breakEnd = null,
        public int $lateToleranceMin = 0,
        public int $checkinOpenMin = 60,
        public ?int $minDailyMinutes = null,
        public ?string $reason = null,
        public ?int $versionId = null,
        public ?string $effectiveFrom = null,
    ) {}

    public static function dayOff(string $source, ?string $reason, ?string $patternName = null): self
    {
        return new self(source: $source, isDayOff: true, patternName: $patternName, reason: $reason);
    }

    public static function fromArray(array $a): self
    {
        return new self(
            source: $a['source'],
            isDayOff: $a['is_day_off'],
            type: $a['type'] ?? 'fixed',
            workPatternId: $a['work_pattern_id'] ?? null,
            patternName: $a['pattern_name'] ?? null,
            startTime: $a['start_time'] ?? null,
            endTime: $a['end_time'] ?? null,
            breakStart: $a['break_start'] ?? null,
            breakEnd: $a['break_end'] ?? null,
            lateToleranceMin: $a['late_tolerance_min'] ?? 0,
            checkinOpenMin: $a['checkin_open_min'] ?? 60,
            minDailyMinutes: $a['min_daily_minutes'] ?? null,
            reason: $a['reason'] ?? null,
            versionId: $a['version_id'] ?? null,
            effectiveFrom: $a['effective_from'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'is_day_off' => $this->isDayOff,
            'type' => $this->type,
            'work_pattern_id' => $this->workPatternId,
            'pattern_name' => $this->patternName,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'break_start' => $this->breakStart,
            'break_end' => $this->breakEnd,
            'late_tolerance_min' => $this->lateToleranceMin,
            'checkin_open_min' => $this->checkinOpenMin,
            'min_daily_minutes' => $this->minDailyMinutes,
            'reason' => $this->reason,
            'version_id' => $this->versionId,
            'effective_from' => $this->effectiveFrom,
        ];
    }

    public function isFlexible(): bool
    {
        return $this->type === 'flexible';
    }

    public function crossesMidnight(): bool
    {
        return ! $this->isDayOff && $this->endTime !== null && $this->endTime <= $this->startTime;
    }

    /** Jam mulai shift pada tanggal kerja $date, di zona $tz. */
    public function startAt(string $date, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse("$date {$this->startTime}", $tz);
    }

    /** Jam selesai; pindah ke hari berikutnya bila shift melewati tengah malam. */
    public function endAt(string $date, string $tz): CarbonImmutable
    {
        $end = CarbonImmutable::parse("$date {$this->endTime}", $tz);

        return $this->crossesMidnight() ? $end->addDay() : $end;
    }

    public function opensAt(string $date, string $tz): CarbonImmutable
    {
        return $this->startAt($date, $tz)->subMinutes($this->checkinOpenMin);
    }

    public function lateAfter(string $date, string $tz): CarbonImmutable
    {
        return $this->startAt($date, $tz)->addMinutes($this->lateToleranceMin);
    }

    /** Menit istirahat yang beririsan dengan rentang [$from, $to]. */
    public function breakOverlapMinutes(string $date, string $tz, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if (! $this->breakStart || ! $this->breakEnd) {
            return 0;
        }
        $bs = CarbonImmutable::parse("$date {$this->breakStart}", $tz);
        $be = CarbonImmutable::parse("$date {$this->breakEnd}", $tz);
        $start = $from->max($bs);
        $end = $to->min($be);

        return $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
    }
}
