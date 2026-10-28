<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Nilai attendances.status. */
enum AttendanceStatus: string implements HasLabel, HasColor
{
    case Present = 'present';
    case Late = 'late';
    case Incomplete = 'incomplete';
    case Absent = 'absent';
    case Leave = 'leave';
    case Sick = 'sick';
    case Duty = 'duty';
    case Holiday = 'holiday';

    public function getLabel(): string
    {
        return match ($this) {
            self::Present => 'Tepat waktu',
            self::Late => 'Terlambat',
            self::Incomplete => 'Tidak absen pulang',
            self::Absent => 'Alpa',
            self::Leave => 'Izin/Cuti',
            self::Sick => 'Sakit',
            self::Duty => 'Tugas luar',
            self::Holiday => 'Libur',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Late => 'warning',
            self::Incomplete => 'warning',
            self::Absent => 'danger',
            self::Leave => 'info',
            self::Sick => 'info',
            self::Duty => 'info',
            self::Holiday => 'gray',
        };
    }
}
