<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Enums\LeaveStatus;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Support\Rupiah;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        $review = fn (string $name, string $label, string $color, bool $approve) => self::reviewAction($name, $label, $color, $approve);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'employee.unit', 'approver', 'submitter.user', 'leaveType',
                'groupMembers.employee.user', 'similarTo.employee.user']))
            ->defaultSort('created_at', 'desc')
            // Klik baris membuka detail; Setujui/Tolak juga tersedia di dalamnya.
            ->recordAction('view')
            ->columns(self::columns())
            ->filters([
                SelectFilter::make('status')->options(LeaveStatus::class)->default(LeaveStatus::Pending->value),
                SelectFilter::make('leave_type_id')->label('Jenis')->relationship('leaveType', 'name'),
            ])
            ->recordActions([
                ViewAction::make()->label('Detail')->slideOver()->modalWidth(Width::ThreeExtraLarge)
                    ->modalHeading(fn (LeaveRequest $r) => "{$r->typeLabel()} · {$r->employee->name}")
                    ->extraModalFooterActions(fn () => [
                        $review('approveFromDetail', 'Setujui', 'success', true)->cancelParentActions(),
                        $review('rejectFromDetail', 'Tolak', 'danger', false)->cancelParentActions(),
                        self::cancelDayAction()->cancelParentActions(),
                    ]),
                $review('approve', 'Setujui', 'success', true),
                $review('reject', 'Tolak', 'danger', false),
            ]);
    }

    private static function reviewAction(string $name, string $label, string $color, bool $approve): Action
    {
        return Action::make($name)->label($label)->color($color)
            ->icon($approve ? Heroicon::OutlinedCheck : Heroicon::OutlinedXMark)
            ->visible(fn (LeaveRequest $r) => $r->status === LeaveStatus::Pending)
            ->schema([Textarea::make('note')->label('Catatan')->rows(2)->required(! $approve)])
            ->action(function (LeaveRequest $r, array $data) use ($approve) {
                $svc = app(LeaveService::class);
                $approve ? $svc->approve($r, auth()->user(), $data['note'] ?? null) : $svc->reject($r, auth()->user(), $data['note']);
                Notification::make()->title($approve ? 'Pengajuan disetujui' : 'Pengajuan ditolak')->success()->send();
            });
    }

    /** Admin SDM membatalkan satu hari izin yang sudah disetujui (pegawai ternyata masuk kerja). */
    private static function cancelDayAction(): Action
    {
        return Action::make('cancelDay')->label('Batalkan satu hari')->color('gray')->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (LeaveRequest $r) => $r->status === LeaveStatus::Approved && auth()->user()->isAdmin())
            ->modalDescription('Pegawai ternyata masuk kerja pada hari itu. Hari itu dikeluarkan dari izin, jatah dan potongannya kembali. '
                .'Hari ini/ke depan: pegawai bisa absen seperti biasa. Hari yang sudah lewat tanpa absen menjadi alpa; catat presensinya lewat "Catat manual".')
            ->schema([
                DatePicker::make('date')->label('Tanggal')->required()->native(false)
                    ->minDate(fn (LeaveRequest $record) => $record->start_date)->maxDate(fn (LeaveRequest $record) => $record->end_date)
                    ->disabledDates(fn (LeaveRequest $record) => $record->cancelled_dates ?? []),
                Textarea::make('note')->label('Alasan')->rows(2)->maxLength(500),
            ])
            ->action(function (LeaveRequest $r, array $data) {
                app(LeaveService::class)->cancelDay($r, Carbon::parse($data['date'])->toDateString(), auth()->user(), $data['note'] ?? null);
                Notification::make()->title('Izin hari itu dibatalkan')->success()->send();
            });
    }

    private static function columns(): array
    {
        return [
            TextColumn::make('employee.user.name')->label('Pegawai')->searchable()
                ->description(fn (LeaveRequest $r) => collect([
                    $r->employee->unit?->name,
                    $r->isSubmittedByOther() ? 'Diajukan oleh '.$r->submitter?->name : null,
                ])->filter()->implode(' · ')),
            TextColumn::make('group_id')->label('Bersama')->placeholder('-')
                ->formatStateUsing(fn (LeaveRequest $r) => ($r->groupMembers->count() - 1).' rekan')
                ->tooltip(fn (LeaveRequest $r) => $r->groupMembers->where('id', '!=', $r->id)
                    ->map(fn (LeaveRequest $l) => $l->employee->name.' ('.$l->status->getLabel().')')->implode(', ')),
            TextColumn::make('leaveType.name')->label('Jenis')->badge()->color('info'),
            TextColumn::make('start_date')->label('Tanggal')
                ->formatStateUsing(fn (LeaveRequest $r) => $r->start_date->translatedFormat('d M').($r->end_date->ne($r->start_date) ? ' – '.$r->end_date->translatedFormat('d M Y') : ' '.$r->start_date->format('Y'))),
            TextColumn::make('days')->label('Hari kerja')->placeholder('-'),
            TextColumn::make('reason')->label('Alasan')->limit(60)->tooltip(fn (LeaveRequest $r) => $r->reason),
            TextColumn::make('attachment_path')->label('Lampiran')->formatStateUsing(fn () => 'Lihat')
                ->url(fn (LeaveRequest $r) => $r->attachmentUrl(), true)
                // Foto yang mirip lampiran pengajuan lain (mis. surat yang sama difoto ulang).
                ->icon(fn (LeaveRequest $r) => $r->similarTo ? Heroicon::OutlinedExclamationTriangle : null)->iconColor('danger')
                ->description(fn (LeaveRequest $r) => $r->similarTo
                    ? "Mirip lampiran {$r->similarTo->employee->name} ({$r->similarTo->typeLabel()} {$r->similarTo->rangeLabel()})" : null)
                ->placeholder('-'),
            TextColumn::make('status')->badge(),
            TextColumn::make('approver.name')->label('Diputuskan oleh')->placeholder('-')
                ->description(fn (LeaveRequest $r) => $r->review_note),
        ];
    }

    /** Detail satu pengajuan; dibuka dengan mengeklik barisnya. */
    public static function infolist(Schema $schema): Schema
    {
        $tz = config('attendance.timezone');
        $attachment = fn (string $name, string $label, callable $record) => [
            ImageEntry::make("{$name}_image")->label($label)->imageHeight(320)
                ->state(fn (LeaveRequest $r) => ($l = $record($r))?->attachmentIsImage() ? $l->attachmentUrl() : null)
                ->url(fn (LeaveRequest $r) => $record($r)?->attachmentUrl(), true)
                ->visible(fn (LeaveRequest $r) => (bool) $record($r)?->attachmentIsImage()),
            TextEntry::make("{$name}_file")->label($label)->state('Buka PDF')->icon(Heroicon::OutlinedDocument)->color('primary')
                ->url(fn (LeaveRequest $r) => $record($r)?->attachmentUrl(), true)
                ->visible(fn (LeaveRequest $r) => $record($r)?->attachment_path && ! $record($r)->attachmentIsImage()),
        ];

        return $schema->columns(1)->components([
            Section::make('Pengajuan')->columns(3)->components([
                TextEntry::make('employee.user.name')->label('Pegawai')
                    ->helperText(fn (LeaveRequest $r) => collect([$r->employee->nip, $r->employee->unit?->name])->filter()->implode(' · ')),
                TextEntry::make('leaveType.name')->label('Jenis')->badge()->color('info'),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('start_date')->label('Tanggal')->state(fn (LeaveRequest $r) => $r->rangeLabel()),
                TextEntry::make('days')->label('Hari kerja')->suffix(' hari')
                    ->state(fn (LeaveRequest $r) => $r->days ?? app(LeaveService::class)->workdays(
                        $r->employee, $r->start_date->toImmutable(), $r->end_date->toImmutable())),
                TextEntry::make('deduction')->label('Potongan')
                    ->state(fn (LeaveRequest $r) => ($amount = $r->deductionSummary()['amount']) > 0 ? Rupiah::format($amount) : 'Tidak dipotong')
                    ->helperText(fn (LeaveRequest $r) => match (true) {
                        $r->status === LeaveStatus::Approved => 'Tercatat di presensi',
                        $r->status === LeaveStatus::Rejected => 'Ditolak, tidak dipotong',
                        default => 'Perkiraan: hari kerja × '.Rupiah::format($r->deduction_per_day).'/hari (nominal saat diajukan)',
                    }),
                TextEntry::make('created_at')->label('Diajukan')
                    ->state(fn (LeaveRequest $r) => $r->created_at->setTimezone($tz)->translatedFormat('d M Y H:i'))
                    ->helperText(fn (LeaveRequest $r) => $r->isSubmittedByOther() ? 'oleh '.$r->submitter?->name : null),
                TextEntry::make('cancelled_dates')->label('Hari dibatalkan')->badge()->color('gray')
                    ->state(fn (LeaveRequest $r) => collect($r->cancelled_dates ?? [])->map(fn (string $d) => Carbon::parse($d)->translatedFormat('d M Y'))->all())
                    ->helperText('Pegawai masuk kerja; tidak dihitung sebagai izin.')
                    ->visible(fn (LeaveRequest $r) => ! empty($r->cancelled_dates)),
                TextEntry::make('reason')->label('Alasan')->columnSpan(2)->prose(),
            ]),
            Section::make('Lampiran')->columns(2)
                ->description(fn (LeaveRequest $r) => $r->similarTo
                    ? 'Foto ini mirip lampiran pengajuan lain. Bandingkan keduanya sebelum menyetujui.' : null)
                ->visible(fn (LeaveRequest $r) => $r->attachment_path !== null)
                ->components([
                    ...$attachment('own', 'Lampiran pengajuan ini', fn (LeaveRequest $r) => $r),
                    TextEntry::make('similar_info')->label('Mirip dengan')->color('danger')->icon(Heroicon::OutlinedExclamationTriangle)
                        ->state(fn (LeaveRequest $r) => $r->similarTo
                            ? "{$r->similarTo->employee->name} · {$r->similarTo->typeLabel()} {$r->similarTo->rangeLabel()} ({$r->similarTo->status->getLabel()})" : null)
                        ->visible(fn (LeaveRequest $r) => $r->similarTo !== null),
                    ...$attachment('similar', 'Lampiran pembanding', fn (LeaveRequest $r) => $r->similarTo),
                ]),
            Section::make('Izin bersama')
                ->description('Setiap anggota disetujui kepala unitnya masing-masing.')
                ->visible(fn (LeaveRequest $r) => $r->group_id !== null)
                ->components([
                    RepeatableEntry::make('groupMembers')->hiddenLabel()->columns(3)->contained(false)
                        ->schema([
                            TextEntry::make('employee.user.name')->label('Nama')
                                ->weight(fn (LeaveRequest $record) => $record->employee_id === $record->submitted_by ? 'bold' : null)
                                ->helperText(fn (LeaveRequest $record) => $record->employee_id === $record->submitted_by ? 'Pengaju' : null),
                            TextEntry::make('employee.unit.name')->label('Unit')->placeholder('-'),
                            TextEntry::make('status')->label('Status')->badge(),
                        ]),
                ]),
            Section::make('Keputusan')->columns(3)
                ->visible(fn (LeaveRequest $r) => $r->status !== LeaveStatus::Pending)
                ->components([
                    TextEntry::make('approver.name')->label('Diputuskan oleh')->placeholder('-'),
                    TextEntry::make('approved_at')->label('Waktu')
                        ->state(fn (LeaveRequest $r) => $r->approved_at?->setTimezone($tz)->translatedFormat('d M Y H:i'))->placeholder('-'),
                    TextEntry::make('review_note')->label('Catatan')->placeholder('-'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveRequests::route('/')];
    }
}
