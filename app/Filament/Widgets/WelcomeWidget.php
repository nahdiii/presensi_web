<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget;

/** Kartu sapaan bawaan Filament, dibentangkan selebar dasbor. */
class WelcomeWidget extends AccountWidget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';
}
