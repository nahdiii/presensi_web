<?php

namespace App\Filament\Concerns;

/** Resource yang hanya boleh dibuka Admin SDM. */
trait AdminOnly
{
    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
