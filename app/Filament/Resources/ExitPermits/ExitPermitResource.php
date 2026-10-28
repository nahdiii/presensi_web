<?php

namespace App\Filament\Resources\ExitPermits;

use App\Enums\LeaveStatus;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\ExitPermits\Pages\ListExitPermits;
use App\Models\ExitPermit;
use App\Services\ExitPermitService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExitPermitResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = ExitPermit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static ?string $modelLabel = 'izin keluar';

    protected static ?string $pluralModelLabel = 'Izin Keluar';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Presensi';
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->whereNull('back_at')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Sedang di luar kampus';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $tz = config('attendance.timezone');
        $decide = fn (string $name, string $label, string $color, bool $approve) => Action::make($name)->label($label)->color($color)
            ->icon($approve ? Heroicon::OutlinedCheck : Heroicon::OutlinedXMark)
            ->visible(fn (ExitPermit $r) => $r->status === LeaveStatus::Pending)
            ->schema([Textarea::make('note')->label('Catatan')->rows(2)->required(! $approve)])
            ->action(function (ExitPermit $r, array $data) use ($approve) {
                app(ExitPermitService::class)->decide($r, auth()->user(), $approve, $data['note'] ?? null);
                Notification::make()->title($approve ? 'Izin keluar disetujui' : 'Izin keluar ditolak')->success()->send();
            });

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'employee.unit', 'approver']))
            ->defaultSort('out_at', 'desc')
            ->columns([
                TextColumn::make('out_at')->label('Keluar')->dateTime('d M Y, H:i', $tz)->sortable(),
                TextColumn::make('employee.user.name')->label('Pegawai')->searchable()->weight('semibold')
                    ->description(fn (ExitPermit $r) => $r->employee->unit?->name),
                TextColumn::make('reason')->label('Alasan')->limit(50)->tooltip(fn (ExitPermit $r) => $r->reason),
                TextColumn::make('back_at')->label('Kembali')->dateTime('H:i', $tz)
                    ->placeholder('Masih di luar')->badge(fn (ExitPermit $r) => $r->isActive())->color(fn (ExitPermit $r) => $r->isActive() ? 'warning' : null),
                TextColumn::make('duration')->label('Durasi')
                    ->state(fn (ExitPermit $r) => (int) $r->out_at->diffInMinutes($r->back_at ?? now()).' mnt'),
                TextColumn::make('status')->badge(),
                TextColumn::make('approver.name')->label('Diputuskan oleh')->placeholder('-')
                    ->description(fn (ExitPermit $r) => $r->review_note),
            ])
            ->filters([
                TernaryFilter::make('active')->label('Masih di luar')
                    ->queries(true: fn (Builder $query) => $query->whereNull('back_at'), false: fn (Builder $query) => $query->whereNotNull('back_at')),
                SelectFilter::make('status')->options(LeaveStatus::class),
            ])
            ->recordActions([$decide('approve', 'Setujui', 'success', true), $decide('reject', 'Tolak', 'danger', false)]);
    }

    public static function getPages(): array
    {
        return ['index' => ListExitPermits::route('/')];
    }
}
