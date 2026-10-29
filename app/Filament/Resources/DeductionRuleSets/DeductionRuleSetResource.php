<?php

namespace App\Filament\Resources\DeductionRuleSets;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\DeductionRuleSets\Pages\ManageDeductionRuleSets;
use App\Models\DeductionRuleSet;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Aturan potongan terlambat (TL) dan pulang sebelum waktu (PSW). Aturan
 * tidak bisa diedit: perubahan dibuat sebagai aturan baru dengan tanggal
 * berlaku, sehingga riwayatnya tersimpan. Aturan yang belum berlaku boleh dihapus.
 */
class DeductionRuleSetResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = DeductionRuleSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $modelLabel = 'aturan potongan';

    protected static ?string $pluralModelLabel = 'Aturan Potongan';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    /** Isian awal form: salinan aturan terbaru, atau contoh TL/PSW 1–4. */
    public static function startingValues(): array
    {
        $latest = DeductionRuleSet::latest('effective_from')->latest('id')->first();

        return $latest?->only(['late_tiers', 'early_leave_tiers', 'no_checkout_percent', 'absent_percent']) ?? DeductionRuleSet::defaults();
    }

    public static function form(Schema $schema): Schema
    {
        $tz = config('attendance.timezone');
        $tiers = fn (string $name, string $label, string $prefix) => Repeater::make($name)->label($label)
            ->default(fn () => self::startingValues()[$name])
            ->columns(4)->reorderable(false)->minItems(1)->addActionLabel('Tambah tingkat')
            ->itemLabel(fn (array $state) => $state['code'] ?? null)
            ->schema([
                TextInput::make('code')->label('Kode')->required()->maxLength(10)->placeholder("{$prefix}1"),
                TextInput::make('from')->label('Dari menit ke-')->numeric()->integer()->required()->minValue(1),
                TextInput::make('to')->label('Sampai menit ke-')->numeric()->integer()->gte('from')
                    ->placeholder('tanpa batas'),
                TextInput::make('percent')->label('Potongan')->numeric()->required()->minValue(0)->maxValue(100)->suffix('%'),
            ]);

        return $schema->components([
            Section::make('Berlaku mulai')->columns(2)->columnSpanFull()
                ->description('Aturan dipakai mulai tanggal ini sampai ada aturan baru. Bila tanggalnya hari ini atau sudah lewat, '
                    .'potongan presensi sejak tanggal itu dihitung ulang otomatis.')
                ->components([
                    DatePicker::make('effective_from')->label('Berlaku mulai')->native(false)->required()
                        ->default(fn () => now($tz)->toDateString())
                        ->minDate(fn () => now($tz)->subDays(config('attendance.recalculate_max_days'))->toDateString()),
                    TextInput::make('note')->label('Dasar aturan')->maxLength(255)->placeholder('mis. Peraturan Rektor No. 5/2026'),
                ]),
            Section::make('Terlambat masuk (TL)')->columnSpanFull()
                ->description('Menit terlambat dihitung otomatis dari jam masuk yang berlaku hari itu, mulai dari jam masuk (bukan dari akhir toleransi). '
                    .'Datang masih dalam toleransi tidak dihitung terlambat.')
                ->components([$tiers('late_tiers', 'Tingkat', 'TL')]),
            Section::make('Pulang sebelum waktu (PSW)')->columnSpanFull()
                ->description('Menit dihitung dari jam pulang yang berlaku hari itu. Untuk pola fleksibel: kekurangan dari minimal jam hadir.')
                ->components([$tiers('early_leave_tiers', 'Tingkat', 'PSW')]),
            Section::make('Lainnya')->columns(2)->columnSpanFull()->components([
                TextInput::make('no_checkout_percent')->label('Tidak absen pulang ('.DeductionRuleSet::NO_CHECKOUT_CODE.')')
                    ->numeric()->required()->minValue(0)->maxValue(100)->suffix('%')
                    ->default(fn () => self::startingValues()['no_checkout_percent']),
                TextInput::make('absent_percent')->label('Alpa (tidak masuk tanpa keterangan)')
                    ->numeric()->required()->minValue(0)->maxValue(100)->suffix('%')
                    ->default(fn () => self::startingValues()['absent_percent'])
                    ->helperText('Juga batas atas potongan satu hari (terlambat + pulang cepat).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $today = fn () => now(config('attendance.timezone'))->toDateString();
        $current = fn () => DeductionRuleSet::forDate(auth()->user()->institution_id, $today())?->id;

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->defaultSort(fn ($query) => $query->orderByDesc('effective_from')->orderByDesc('id'))
            ->emptyStateHeading('Belum ada aturan potongan')
            ->emptyStateDescription('Selama belum ada aturan, presensi tidak dikenai potongan.')
            ->columns([
                TextColumn::make('effective_from')->label('Berlaku mulai')->date('d M Y')->badge()
                    ->color(fn (DeductionRuleSet $r) => match (true) {
                        $r->id === $current() => 'success',
                        $r->effective_from->toDateString() > $today() => 'info',
                        default => 'gray',
                    })
                    ->description(fn (DeductionRuleSet $r) => match (true) {
                        $r->id === $current() => 'Berlaku sekarang',
                        $r->effective_from->toDateString() > $today() => 'Akan berlaku',
                        default => null,
                    }),
                TextColumn::make('summary')->label('Aturan')->state(fn (DeductionRuleSet $r) => $r->summary())->wrap(),
                TextColumn::make('note')->label('Dasar')->placeholder('-')->wrap(),
                TextColumn::make('creator.name')->label('Dibuat oleh')->placeholder('-')
                    ->description(fn (DeductionRuleSet $r) => $r->created_at?->setTimezone(config('attendance.timezone'))->translatedFormat('d M Y H:i')),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()->visible(fn (DeductionRuleSet $r) => $r->effective_from->toDateString() > $today()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeductionRuleSets::route('/')];
    }
}
