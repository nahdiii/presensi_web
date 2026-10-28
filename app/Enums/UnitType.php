<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Jenis unit organisasi. */
enum UnitType: string implements HasLabel
{
    case Fakultas = 'fakultas';
    case Prodi = 'prodi';
    case Biro = 'biro';
    case Lembaga = 'lembaga';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fakultas => 'Fakultas',
            self::Prodi => 'Program studi',
            self::Biro => 'Biro',
            self::Lembaga => 'Lembaga',
        };
    }
}
