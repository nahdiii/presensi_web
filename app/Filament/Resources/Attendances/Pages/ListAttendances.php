<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Exports\MonthlyAttendanceExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Jobs\RecalculateAttendances;
use App\Models\Employee;
use App\Models\Unit;
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
        return [self::manualAction(), self::recalculateAction(), self::exportAction()];
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

    /**
     * Hitung ulang terlambat, pulang cepat, dan potongan dengan jadwal yang
     * berlaku sekarang — mis. setelah pola jadwal unit/pegawai/kampus diganti
     * atau kalender libur diperbaiki. Perubahan jam lewat Pola Jadwal dan
     * aturan potongan baru sudah memicu hitung ulang sendiri.
     */
    public static function recalculateAction(): Action
    {
        $tz = config('attendance.timezone');

        return Action::make('recalculate')->label('Hitung ulang')->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->visible(fn () => auth()->user()->isAdmin())
            ->modalDescription('Menit terlambat, pulang cepat, dan potongan dihitung ulang dari jam absen dan jadwal yang berlaku pada tiap tanggal. '
                .'Jam absen pegawai tidak diubah.')
            ->schema([
                DatePicker::make('from')->label('Dari')->required()->native(false)
                    ->minDate(now($tz)->subDays(config('attendance.recalculate_max_days'))->toDateString())
                    ->maxDate(now($tz)->toDateString())->default(now($tz)->startOfMonth()->toDateString()),
                DatePicker::make('until')->label('Sampai')->required()->native(false)->afterOrEqual('from')
                    ->maxDate(now($tz)->toDateString())->default(now($tz)->toDateString()),
                Select::make('unit_id')->label('Unit')->placeholder('Semua unit')->searchable()
                    ->options(fn () => Unit::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->modalSubmitActionLabel('Hitung ulang')
            ->action(function (array $data) {
                $unit = isset($data['unit_id']) ? Unit::find($data['unit_id']) : null;
                RecalculateAttendances::dispatch(auth()->user()->institution_id, $data['from'], $data['until'],
                    'Hitung ulang '.($unit?->name ?? 'semua unit'),
                    $unit ? Employee::whereIn('unit_id', $unit->descendantIds())->pluck('id')->all() : null,
                    auth()->id());
                Notification::make()->title('Hitung ulang dimulai')->body('Anda akan diberi tahu bila sudah selesai.')->info()->send();
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
