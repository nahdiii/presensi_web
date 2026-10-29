<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Exports\MonthlyAttendanceExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Employee;
use App\Services\CorrectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [self::manualAction(), self::exportAction()];
    }

    /** Admin SDM mencatat presensi pegawai yang tidak bisa absen lewat aplikasi (HP rusak/hilang, gangguan). */
    public static function manualAction(): Action
    {
        $tz = config('attendance.timezone');

        return Action::make('manual')->label('Catat manual')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->visible(fn () => auth()->user()->isAdmin())
            ->modalDescription('Untuk pegawai yang tidak bisa absen lewat aplikasi. Status dihitung dari jadwal hari itu dan catatan ini tersimpan di Koreksi Absen atas nama Anda.')
            ->schema([
                Select::make('employee_id')->label('Pegawai')->required()->searchable()
                    ->options(fn () => Employee::with('user')->get()->sortBy('user.name')
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user->name.($e->nip ? " ({$e->nip})" : '')])->all()),
                DatePicker::make('date')->label('Tanggal')->required()->native(false)
                    ->maxDate(now($tz)->toDateString())->default(now($tz)->toDateString()),
                TimePicker::make('check_in')->label('Jam masuk')->seconds(false)->required(),
                TimePicker::make('check_out')->label('Jam pulang')->seconds(false),
                Textarea::make('reason')->label('Alasan')->required()->rows(2)->maxLength(1000)
                    ->placeholder('mis. HP pegawai rusak, hadir dikonfirmasi kepala unit'),
            ])
            ->modalSubmitActionLabel('Simpan')
            ->action(function (array $data) {
                app(CorrectionService::class)->recordManual(
                    Employee::findOrFail($data['employee_id']), $data['date'], $data['check_in'], $data['check_out'] ?? null,
                    $data['reason'], auth()->user(),
                );
                Notification::make()->title('Presensi dicatat')->success()->send();
            });
    }

    /** Dipakai juga di dashboard. */
    public static function exportAction(): Action
    {
        $months = collect(range(0, 11))->mapWithKeys(function ($i) {
            $m = now()->startOfMonth()->subMonths($i);

            return [$m->format('Y-m') => $m->translatedFormat('F Y')];
        })->all();

        return Action::make('export')->label('Ekspor rekap bulanan')->icon(Heroicon::OutlinedArrowDownTray)
            ->schema([Select::make('month')->label('Bulan')->options($months)->default(array_key_first($months))->required()])
            ->modalSubmitActionLabel('Unduh .xlsx')
            ->action(fn (array $data) => Excel::download(
                new MonthlyAttendanceExport($data['month'], auth()->user()->supervisedUnitIds()),
                "rekap-presensi-{$data['month']}.xlsx",
            ));
    }
}
