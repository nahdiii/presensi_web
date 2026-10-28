<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /** Tiga kolom supaya grafik tren (2 kolom) dan komposisi (1 kolom) sejajar. */
    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
