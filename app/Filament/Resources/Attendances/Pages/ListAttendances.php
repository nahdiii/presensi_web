<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Exports\MonthlyAttendanceExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        $months = collect(range(0, 11))->mapWithKeys(function ($i) {
            $m = now()->startOfMonth()->subMonths($i);

            return [$m->format('Y-m') => $m->translatedFormat('F Y')];
        })->all();

        return [
            Action::make('export')->label('Ekspor rekap bulanan')->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([Select::make('month')->label('Bulan')->options($months)->default(array_key_first($months))->required()])
                ->modalSubmitActionLabel('Unduh .xlsx')
                ->action(fn (array $data) => Excel::download(
                    new MonthlyAttendanceExport($data['month'], auth()->user()->supervisedUnitIds()),
                    "rekap-presensi-{$data['month']}.xlsx",
                )),
        ];
    }
}
