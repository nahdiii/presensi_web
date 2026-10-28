<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Pimpinan unit hanya melihat data pegawai di unitnya (beserta sub-unit);
 * Admin SDM melihat semuanya.
 */
trait ScopedToSupervisedUnits
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $units = auth()->user()?->supervisedUnitIds();
        if ($units === null) {
            return $query;
        }
        return static::unitColumn()
            ? $query->whereIn(static::unitColumn(), $units)
            : $query->whereHas('employee', fn (Builder $query) => $query->whereIn('unit_id', $units));
    }

    /** Kolom unit di tabel resource ini; null = lewat relasi employee. */
    protected static function unitColumn(): ?string
    {
        return null;
    }
}
