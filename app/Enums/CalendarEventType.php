<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Jenis hari libur. */
enum CalendarEventType: string implements HasLabel, HasColor
{
    case LiburNasional = 'libur_nasional';
    case CutiBersama = 'cuti_bersama';
    case LiburKampus = 'libur_kampus';

    public function getLabel(): string
    {
        return match ($this) {
            self::LiburNasional => 'Libur nasional',
            self::CutiBersama => 'Cuti bersama',
            self::LiburKampus => 'Libur kampus',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LiburNasional => 'danger',
            self::CutiBersama => 'warning',
            self::LiburKampus => 'info',
        };
    }
}
