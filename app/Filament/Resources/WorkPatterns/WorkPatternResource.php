<?php

namespace App\Filament\Resources\WorkPatterns;

use App\Enums\WorkPatternType;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\WorkPatterns\Pages\CreateWorkPattern;
use App\Filament\Resources\WorkPatterns\Pages\EditWorkPattern;
use App\Filament\Resources\WorkPatterns\Pages\ListWorkPatterns;
use App\Filament\Resources\WorkPatterns\RelationManagers\VersionsRelationManager;
use App\Jobs\RecalculateAttendances;
use App\Models\WorkPattern;
use App\Models\WorkPatternDay;
use App\Models\WorkPatternVersion;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkPatternResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = WorkPattern::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $modelLabel = 'pola jadwal';

    protected static ?string $pluralModelLabel = 'Pola Jadwal';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public static function form(Schema $schema): Schema
    {
        $time = fn (string $name, string $label) => TimePicker::make($name)->label($label)->seconds(false)
            ->hidden(fn (Get $get) => (bool) $get('is_day_off'));

        $tz = config('attendance.timezone');

        return $schema->components([
            Section::make('Berlaku mulai')->columns(2)->columnSpanFull()
                ->description('Jam baru tidak menimpa jam lama: dipakai mulai tanggal ini, hari-hari sebelumnya tetap memakai jam lama. '
                    .'Bila tanggalnya hari ini atau sudah lewat, presensi sejak tanggal itu (terlambat, pulang cepat, potongan) dihitung ulang otomatis.')
                ->components([
                    DatePicker::make('effective_from')->label('Jam di bawah berlaku mulai')->native(false)->required()
                        ->default(fn () => now($tz)->toDateString())
                        ->minDate(fn () => now($tz)->subDays(config('attendance.recalculate_max_days'))->toDateString())
                        ->dehydrated(false),
                    TextInput::make('change_note')->label('Alasan / dasar perubahan')->maxLength(255)->dehydrated(false)
                        ->placeholder('mis. SK Rektor No. 12/2026: jam masuk Kampus 2 jadi 08.00'),
                ]),
            Section::make('Pola')->columns(3)->columnSpanFull()->components([
                TextInput::make('name')->label('Nama')->placeholder('Tendik Reguler')->required()->maxLength(120),
                Select::make('type')->label('Tipe')->options(WorkPatternType::class)->required()->live()
                    ->helperText('Fleksibel: tidak ada terlambat, dinilai dari total jam hadir.'),
                TextInput::make('checkin_open_min')->label('Absen dibuka (menit sebelum jam masuk)')->numeric()->default(60)->required(),
                TextInput::make('late_tolerance_min')->label('Toleransi terlambat (menit)')->numeric()->default(15)
                    ->hidden(fn (Get $get) => in_array($get('type'), [WorkPatternType::Flexible, 'flexible'], true)),
                TextInput::make('min_daily_minutes')->label('Minimal hadir per hari (menit)')->numeric()
                    ->visible(fn (Get $get) => in_array($get('type'), [WorkPatternType::Flexible, 'flexible'], true)),
            ]),
            Section::make('Jam per hari')->columnSpanFull()
                ->description('Jam pulang lebih kecil dari jam masuk berarti shift melewati tengah malam.')
                ->components([
                    Repeater::make('days')->hiddenLabel()->relationship()
                        ->orderColumn('weekday')->reorderable(false)->addable(false)->deletable(false)
                        ->default(collect(WorkPatternDay::WEEKDAYS)->map(fn ($n, $wd) => [
                            'weekday' => $wd, 'is_day_off' => $wd >= 6, 'start_time' => $wd < 6 ? '07:30' : null, 'end_time' => $wd < 6 ? '16:00' : null,
                        ])->values()->all())
                        ->itemLabel(fn (array $state) => WorkPatternDay::WEEKDAYS[$state['weekday'] ?? 0] ?? null)
                        ->columns(5)
                        ->schema([
                            Toggle::make('is_day_off')->label('Libur')->live()->inline(false),
                            $time('start_time', 'Masuk')->required(fn (Get $get) => ! $get('is_day_off')),
                            $time('end_time', 'Pulang')->required(fn (Get $get) => ! $get('is_day_off')),
                            $time('break_start', 'Istirahat mulai'),
                            $time('break_end', 'Istirahat selesai'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('type')->label('Tipe')->badge(),
                TextColumn::make('summary')->label('Jadwal berlaku hari ini')
                    ->state(fn (WorkPattern $r) => $r->versionFor(now(config('attendance.timezone')))->summary())->wrap()
                    ->description(fn (WorkPattern $r) => ($next = self::upcomingVersion($r))
                        ? 'Berubah mulai '.$next->effective_from->translatedFormat('d M Y').': '.$next->summary() : null),
                TextColumn::make('late_tolerance_min')->label('Toleransi')->suffix(' mnt')
                    ->state(fn (WorkPattern $r) => $r->versionFor(now(config('attendance.timezone')))->late_tolerance_min),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('days', 'versions'))
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    /** Perubahan jam terdekat yang belum berlaku. */
    public static function upcomingVersion(WorkPattern $pattern): ?WorkPatternVersion
    {
        $today = now(config('attendance.timezone'))->toDateString();

        return $pattern->versions->first(fn (WorkPatternVersion $v) => $v->effective_from->toDateString() > $today);
    }

    /**
     * Dipanggil setelah form disimpan: mencatat jam di form sebagai versi
     * baru, lalu menghitung ulang presensi bila berlakunya sudah dimulai.
     */
    public static function recordVersion(WorkPattern $pattern, array $data, bool $recalculate = true): void
    {
        $tz = config('attendance.timezone');
        $from = CarbonImmutable::parse($data['effective_from'])->toDateString();
        $version = $pattern->recordVersion($from, $data['change_note'] ?? null, auth()->id());
        if (! $version || ! $recalculate) {
            return;
        }
        $label = CarbonImmutable::parse($from)->translatedFormat('d M Y');
        $today = now($tz)->toDateString();
        if ($from > $today) {
            Notification::make()->title("Jam baru dijadwalkan berlaku mulai $label")
                ->body('Sampai tanggal itu presensi tetap memakai jam yang sekarang.')->info()->send();

            return;
        }
        RecalculateAttendances::dispatch($pattern->institution_id, $from, $today, "Jam kerja \"{$pattern->name}\" diubah", userId: auth()->id())
            ->afterCommit();
        Notification::make()->title("Jam baru berlaku mulai $label")
            ->body('Presensi sejak tanggal itu sedang dihitung ulang. Anda akan diberi tahu bila sudah selesai.')->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkPatterns::route('/'),
            'create' => CreateWorkPattern::route('/create'),
            'edit' => EditWorkPattern::route('/{record}/edit'),
        ];
    }
}
