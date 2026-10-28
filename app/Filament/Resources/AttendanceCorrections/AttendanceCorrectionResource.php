<?php

namespace App\Filament\Resources\AttendanceCorrections;

use App\Enums\LeaveStatus;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Services\CorrectionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class AttendanceCorrectionResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = AttendanceCorrection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $modelLabel = 'koreksi absen';

    protected static ?string $pluralModelLabel = 'Koreksi Absen';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Presensi';
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', LeaveStatus::Pending)->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false; // Diajukan pegawai lewat aplikasi.
    }

    public static function table(Table $table): Table
    {
        $tz = config('attendance.timezone');
        $recorded = function (AttendanceCorrection $r) use ($tz) {
            $a = Attendance::where('employee_id', $r->employee_id)->whereDate('date', $r->date)->first();
            if (! $a) {
                return 'Tidak ada catatan';
            }

            return ($a->check_in_at?->setTimezone($tz)->format('H:i') ?? '--:--').' – '.($a->check_out_at?->setTimezone($tz)->format('H:i') ?? '--:--')
                .' · '.$a->status->getLabel();
        };

        $review = fn (string $name, string $label, string $color, bool $approve) => Action::make($name)->label($label)->color($color)
            ->icon($approve ? Heroicon::OutlinedCheck : Heroicon::OutlinedXMark)
            ->visible(fn (AttendanceCorrection $r) => $r->status === LeaveStatus::Pending)
            ->modalDescription(fn (AttendanceCorrection $r) => $approve
                ? "Jam yang diajukan akan ditulis ke presensi {$r->date->translatedFormat('d M Y')} dan statusnya dihitung ulang."
                : null)
            ->schema([Textarea::make('note')->label('Catatan')->rows(2)->required(! $approve)])
            ->action(function (AttendanceCorrection $r, array $data) use ($approve) {
                $svc = app(CorrectionService::class);
                $approve ? $svc->approve($r, auth()->user(), $data['note'] ?? null) : $svc->reject($r, auth()->user(), $data['note']);
                Notification::make()->title($approve ? 'Koreksi diterapkan' : 'Koreksi ditolak')->success()->send();
            });

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'employee.unit', 'approver']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('date')->label('Tanggal')->date('D, d M Y')->sortable(),
                TextColumn::make('employee.user.name')->label('Pegawai')->searchable()->weight('semibold')
                    ->description(fn (AttendanceCorrection $r) => $r->employee->unit?->name),
                TextColumn::make('requested')->label('Diajukan')
                    ->state(fn (AttendanceCorrection $r) => ($r->check_in_time ? substr($r->check_in_time, 0, 5) : '—').' – '.($r->check_out_time ? substr($r->check_out_time, 0, 5) : '—'))
                    ->description($recorded),
                TextColumn::make('reason')->label('Alasan')->limit(50)->tooltip(fn (AttendanceCorrection $r) => $r->reason),
                TextColumn::make('attachment_path')->label('Lampiran')->formatStateUsing(fn () => 'Lihat')
                    ->url(fn (AttendanceCorrection $r) => $r->attachment_path
                        ? Storage::disk(config('attendance.photo_disk'))->temporaryUrl($r->attachment_path, now()->addMinutes(10)) : null, true)
                    ->placeholder('-'),
                TextColumn::make('status')->badge(),
                TextColumn::make('approver.name')->label('Diputuskan oleh')->placeholder('-')
                    ->description(fn (AttendanceCorrection $r) => $r->review_note),
            ])
            ->filters([SelectFilter::make('status')->options(LeaveStatus::class)->default(LeaveStatus::Pending->value)])
            ->recordActions([$review('approve', 'Setujui', 'success', true), $review('reject', 'Tolak', 'danger', false)]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendanceCorrections::route('/')];
    }
}
