<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipe pola jadwal mingguan. */
enum WorkPatternType: string implements HasLabel
{
    case Fixed = 'fixed';
    case Flexible = 'flexible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Tetap',
            self::Flexible => 'Fleksibel',
        };
    }
}
