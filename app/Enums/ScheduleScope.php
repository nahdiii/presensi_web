<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cakupan pengecualian jadwal. */
enum ScheduleScope: string implements HasLabel
{
    case Institution = 'institution';
    case Office = 'office';
    case Unit = 'unit';
    case Employee = 'employee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Institution => 'Seluruh institusi',
            self::Office => 'Lokasi kampus',
            self::Unit => 'Unit',
            self::Employee => 'Pegawai',
        };
    }
}
