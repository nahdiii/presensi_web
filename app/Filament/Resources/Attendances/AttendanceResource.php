<?php

namespace App\Filament\Resources\Attendances;

use App\Enums\AttendanceStatus;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Attendances\Pages\ViewAttendance;
use App\Models\Attendance;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class AttendanceResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = Attendance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $modelLabel = 'presensi';

    protected static ?string $pluralModelLabel = 'Presensi';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Presensi';
    }

    public static function canCreate(): bool
    {
        return false; // Lewat aplikasi, koreksi pegawai, atau tombol "Catat manual" (admin).
    }

    private static function time(?\DateTimeInterface $at, Attendance $r): ?string
    {
        return $at ? \Carbon\Carbon::instance($at)->setTimezone($r->checkInOffice?->timezone ?? config('attendance.timezone'))->format('H:i') : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'employee.unit', 'checkInOffice', 'checkOutOffice']))
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Tanggal')->date('D, d M Y')->sortable(),
                TextColumn::make('employee.user.name')->label('Pegawai')->searchable()
                    ->description(fn (Attendance $r) => $r->employee->unit?->name),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('check_in_at')->label('Masuk')
                    ->formatStateUsing(fn ($state, Attendance $r) => self::time($state, $r))
                    ->description(fn (Attendance $r) => $r->checkInOffice?->name),
                TextColumn::make('check_out_at')->label('Pulang')
                    ->formatStateUsing(fn ($state, Attendance $r) => self::time($state, $r))
                    ->description(fn (Attendance $r) => $r->checkOutOffice?->name)->placeholder('-'),
                TextColumn::make('late_minutes')->label('Telat')->suffix(' mnt')->sortable()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),
                TextColumn::make('worked_minutes')->label('Hadir')
                    ->formatStateUsing(fn ($state) => $state ? intdiv($state, 60).'j '.($state % 60).'m' : '-'),
                TextColumn::make('early_leave_note')->label('Catatan')->badge()->color('warning')
                    ->state(fn (Attendance $r) => $r->is_early_leave ? 'Pulang awal' : null)->toggleable(),
                TextColumn::make('check_in_distance_m')->label('Jarak masuk')->suffix(' m')->numeric(0)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('Dari')->native(false),
                    DatePicker::make('until')->label('Sampai')->native(false),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($query, $d) => $query->whereDate('date', '>=', $d))
                    ->when($data['until'] ?? null, fn ($query, $d) => $query->whereDate('date', '<=', $d))),
                SelectFilter::make('status')->options(AttendanceStatus::class)->multiple(),
                SelectFilter::make('unit')->label('Unit')->searchable()
                    ->options(fn () => Unit::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($query, $unit) => $query->whereHas('employee', fn ($e) => $e->where('unit_id', $unit)))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $photo = fn (string $col) => ImageEntry::make($col)->hiddenLabel()->imageHeight(260)
            ->state(fn (Attendance $r) => $r->{$col}
                ? Storage::disk(config('attendance.photo_disk'))->temporaryUrl($r->{$col}, now()->addMinutes(config('attendance.photo_url_ttl')))
                : null);
        $map = fn (string $p) => TextEntry::make("{$p}_lat")->label('Koordinat')
            ->state(fn (Attendance $r) => $r->{"{$p}_lat"} ? $r->{"{$p}_lat"}.', '.$r->{"{$p}_lng"} : null)
            ->url(fn (Attendance $r) => $r->{"{$p}_lat"} ? "https://www.google.com/maps?q={$r->{"{$p}_lat"}},{$r->{"{$p}_lng"}}" : null, true)
            ->placeholder('-');

        return $schema->columns(2)->components([
            Section::make('Ringkasan')->columns(4)->columnSpanFull()->components([
                TextEntry::make('employee.user.name')->label('Pegawai'),
                TextEntry::make('date')->label('Tanggal')->date('l, d M Y'),
                TextEntry::make('status')->badge(),
                TextEntry::make('schedule_snapshot')->label('Jadwal berlaku')
                    ->state(fn (Attendance $r) => ($s = $r->schedule_snapshot) ? (($s['is_day_off'] ?? false) ? 'Libur' : ($s['pattern_name'] ?? '').' '.$s['start_time'].'–'.$s['end_time']) : '-'),
                TextEntry::make('late_minutes')->label('Terlambat')->suffix(' menit'),
                TextEntry::make('worked_minutes')->label('Total hadir')->suffix(' menit'),
                TextEntry::make('is_early_leave')->label('Pulang awal')->formatStateUsing(fn ($s) => $s ? 'Ya' : 'Tidak'),
                TextEntry::make('note')->label('Catatan')->placeholder('-'),
            ]),
            Section::make('Absen masuk')->components([
                TextEntry::make('check_in_at')->label('Jam')->formatStateUsing(fn ($state, Attendance $r) => self::time($state, $r))->placeholder('-'),
                TextEntry::make('checkInOffice.name')->label('Lokasi')->placeholder('-'),
                TextEntry::make('check_in_distance_m')->label('Jarak / akurasi')
                    ->state(fn (Attendance $r) => $r->check_in_at ? round($r->check_in_distance_m).' m / ±'.round($r->check_in_accuracy_m).' m' : null)->placeholder('-'),
                $map('check_in'),
                $photo('check_in_photo_path'),
            ]),
            Section::make('Absen pulang')->components([
                TextEntry::make('check_out_at')->label('Jam')->formatStateUsing(fn ($state, Attendance $r) => self::time($state, $r))->placeholder('-'),
                TextEntry::make('checkOutOffice.name')->label('Lokasi')->placeholder('-'),
                TextEntry::make('check_out_distance_m')->label('Jarak / akurasi')
                    ->state(fn (Attendance $r) => $r->check_out_at ? round($r->check_out_distance_m).' m / ±'.round($r->check_out_accuracy_m).' m' : null)->placeholder('-'),
                $map('check_out'),
                $photo('check_out_photo_path'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendances::route('/'),
            'view' => ViewAttendance::route('/{record}'),
        ];
    }
}
