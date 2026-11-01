<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cara presensi pegawai dihitung. */
enum AttendanceType: string implements HasLabel
{
    case Fixed = 'fixed';
    case Flexible = 'flexible';
    case Session = 'session';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Tetap (jam unit)',
            self::Flexible => 'Fleksibel (total jam)',
            self::Session => 'Per sesi mengajar',
        };
    }

    /** Tipe yang berlaku: selama presensi perkuliahan nonaktif, tipe per sesi absen harian. */
    public function effective(): self
    {
        return $this === self::Session && ! config('attendance.sessions.enabled') ? self::Fixed : $this;
    }

    /** @return array<int, self> Tipe tersimpan yang saat ini berlaku sebagai $type. */
    public static function storedAs(self $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->effective() === $type));
    }
}
