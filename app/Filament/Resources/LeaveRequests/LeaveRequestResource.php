<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
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

class LeaveRequestResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $modelLabel = 'pengajuan izin';

    protected static ?string $pluralModelLabel = 'Izin & Cuti';

    protected static ?int $navigationSort = 2;

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
        $review = fn (string $name, string $label, string $color, bool $approve) => Action::make($name)->label($label)->color($color)
            ->icon($approve ? Heroicon::OutlinedCheck : Heroicon::OutlinedXMark)
            ->visible(fn (LeaveRequest $r) => $r->status === LeaveStatus::Pending)
            ->schema([Textarea::make('note')->label('Catatan')->rows(2)->required(! $approve)])
            ->action(function (LeaveRequest $r, array $data) use ($approve) {
                $svc = app(LeaveService::class);
                $approve ? $svc->approve($r, auth()->user(), $data['note'] ?? null) : $svc->reject($r, auth()->user(), $data['note']);
                Notification::make()->title($approve ? 'Pengajuan disetujui' : 'Pengajuan ditolak')->success()->send();
            });

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'employee.unit', 'approver']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.user.name')->label('Pegawai')->searchable()
                    ->description(fn (LeaveRequest $r) => $r->employee->unit?->name),
                TextColumn::make('type')->label('Jenis')->badge(),
                TextColumn::make('start_date')->label('Tanggal')
                    ->formatStateUsing(fn (LeaveRequest $r) => $r->start_date->translatedFormat('d M').($r->end_date->ne($r->start_date) ? ' – '.$r->end_date->translatedFormat('d M Y') : ' '.$r->start_date->format('Y'))),
                TextColumn::make('days')->label('Hari kerja')->placeholder('-'),
                TextColumn::make('reason')->label('Alasan')->limit(60)->tooltip(fn (LeaveRequest $r) => $r->reason),
                TextColumn::make('attachment_path')->label('Lampiran')->formatStateUsing(fn () => 'Lihat')
                    ->url(fn (LeaveRequest $r) => $r->attachment_path
                        ? Storage::disk(config('attendance.photo_disk'))->temporaryUrl($r->attachment_path, now()->addMinutes(10)) : null, true)
                    ->placeholder('-'),
                TextColumn::make('status')->badge(),
                TextColumn::make('approver.name')->label('Diputuskan oleh')->placeholder('-')
                    ->description(fn (LeaveRequest $r) => $r->review_note),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeaveStatus::class)->default(LeaveStatus::Pending->value),
                SelectFilter::make('type')->label('Jenis')->options(LeaveType::class),
            ])
            ->recordActions([
                $review('approve', 'Setujui', 'success', true),
                $review('reject', 'Tolak', 'danger', false),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveRequests::route('/')];
    }
}
