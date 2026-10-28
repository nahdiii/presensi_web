<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Jenis pegawai kampus. */
enum EmployeeType: string implements HasLabel
{
    case Dosen = 'dosen';
    case Tendik = 'tendik';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dosen => 'Dosen',
            self::Tendik => 'Tenaga kependidikan',
        };
    }
}
