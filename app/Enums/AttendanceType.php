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
}
