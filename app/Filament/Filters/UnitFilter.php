<?php

namespace App\Filament\Filters;

use App\Models\Unit;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filter unit untuk tabel yang barisnya punya relasi employee. Memilih unit
 * induk (mis. fakultas) ikut menampilkan pegawai di semua sub-unitnya, sama
 * seperti cakupan pimpinan unit dan hitung ulang. Pimpinan unit hanya
 * ditawari unit yang ia kepalai beserta sub-unitnya.
 */
class UnitFilter
{
    public static function make(): SelectFilter
    {
        return SelectFilter::make('unit')->label('Unit')->searchable()
            ->options(function () {
                $allowed = auth()->user()?->supervisedUnitIds();

                return Unit::query()->when($allowed !== null, fn (Builder $query) => $query->whereIn('id', $allowed))
                    ->orderBy('name')->pluck('name', 'id')->all();
            })
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                fn (Builder $query, $unit) => $query->whereHas('employee',
                    fn (Builder $employee) => $employee->whereIn('unit_id', Unit::find($unit)?->descendantIds() ?? []))));
    }
}
