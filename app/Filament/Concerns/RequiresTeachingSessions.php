<?php

namespace App\Filament\Concerns;

/** Menu Perkuliahan: tersembunyi dan tidak bisa dibuka selama fiturnya nonaktif. */
trait RequiresTeachingSessions
{
    public static function canAccess(): bool
    {
        return config('attendance.sessions.enabled') && parent::canAccess();
    }
}
