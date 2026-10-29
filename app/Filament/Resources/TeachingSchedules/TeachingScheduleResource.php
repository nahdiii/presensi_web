<?php

namespace App\Filament\Resources\TeachingSchedules;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\TeachingSchedules\Pages\ManageTeachingSchedules;
use App\Models\Employee;
use App\Models\TeachingSchedule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Jadwal mengajar mingguan per semester; dasar presensi per sesi. */
class TeachingScheduleResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = TeachingSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $modelLabel = 'jadwal mengajar';

    protected static ?string $pluralModelLabel = 'Jadwal Mengajar';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Perkuliahan';
    }

    /** @return array<int, string> */
    public static function lecturerOptions(): array
    {
        return Employee::with('user')->get()
            ->sortBy(fn (Employee $e) => $e->user->name)
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user->name.($e->nip ? " ({$e->nip})" : '')])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Dosen')->options(fn () => self::lecturerOptions())->searchable()->required(),
            Select::make('course_id')->label('Mata kuliah')->relationship('course', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->code} · {$record->name}")
                ->searchable(['code', 'name'])->preload()->required()
                ->createOptionForm([
                    TextInput::make('code')->label('Kode')->required()->maxLength(30),
                    TextInput::make('name')->label('Nama mata kuliah')->required()->maxLength(150),
                    TextInput::make('credits')->label('SKS')->numeric()->default(2)->required(),
                ]),
            TextInput::make('class_name')->label('Kelas')->placeholder('A')->required()->maxLength(30),
            Select::make('weekday')->label('Hari')->options(TeachingSchedule::WEEKDAYS)->required(),
            TimePicker::make('start_time')->label('Mulai')->seconds(false)->required(),
            TimePicker::make('end_time')->label('Selesai')->seconds(false)->required()->after('start_time'),
            Select::make('office_id')->label('Lokasi (gedung)')->relationship('office', 'name')->preload()
                ->helperText('Kosongkan bila boleh diabsen di lokasi absen dosen mana pun.'),
            TextInput::make('room')->label('Ruang')->placeholder('R. 2.04')->maxLength(60),
            DatePicker::make('valid_from')->label('Berlaku mulai')->native(false)->required(),
            DatePicker::make('valid_until')->label('Berlaku sampai')->native(false)->required()->afterOrEqual('valid_from'),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'course', 'office']))
            ->defaultSort(fn (Builder $query) => $query->orderBy('weekday')->orderBy('start_time'))
            ->columns([
                TextColumn::make('employee.user.name')->label('Dosen')->searchable()->weight('semibold')
                    ->description(fn (TeachingSchedule $r) => $r->employee->nip),
                TextColumn::make('course.name')->label('Mata kuliah')->searchable()
                    ->description(fn (TeachingSchedule $r) => "{$r->course->code} · {$r->course->credits} SKS"),
                TextColumn::make('class_name')->label('Kelas'),
                TextColumn::make('weekday')->label('Hari')->formatStateUsing(fn (int $state) => TeachingSchedule::WEEKDAYS[$state]),
                TextColumn::make('start_time')->label('Jam')
                    ->formatStateUsing(fn (TeachingSchedule $r) => substr($r->start_time, 0, 5).'–'.substr($r->end_time, 0, 5)),
                TextColumn::make('room')->label('Ruang')->description(fn (TeachingSchedule $r) => $r->office?->name)->placeholder('-'),
                TextColumn::make('valid_until')->label('Berlaku')
                    ->formatStateUsing(fn (TeachingSchedule $r) => $r->valid_from->format('d/m/Y').' – '.$r->valid_until->format('d/m/Y')),
                ToggleColumn::make('is_active')->label('Aktif'),
            ])
            ->filters([
                SelectFilter::make('employee_id')->label('Dosen')->options(fn () => self::lecturerOptions())->searchable(),
                SelectFilter::make('weekday')->label('Hari')->options(TeachingSchedule::WEEKDAYS),
                TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTeachingSchedules::route('/')];
    }
}
