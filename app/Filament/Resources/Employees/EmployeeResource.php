<?php

namespace App\Filament\Resources\Employees;

use App\Enums\AttendanceType;
use App\Enums\EmployeeType;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class EmployeeResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'pegawai';

    protected static ?string $pluralModelLabel = 'Pegawai';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Kepegawaian';
    }

    protected static function unitColumn(): ?string
    {
        return 'unit_id';
    }

    public static function canCreate(): bool
    {
        return auth()->user()->isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // Nonaktifkan saja supaya riwayat presensi tetap utuh.
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Akun')->columns(2)->components([
                TextInput::make('user_name')->label('Nama lengkap')->required()->maxLength(150),
                TextInput::make('user_email')->label('Email')->email()->required()
                    ->unique(table: User::class, column: 'email', ignorable: fn (?Employee $record) => $record?->user),
                TextInput::make('user_password')->label('Password')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(8)
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Kosongkan bila tidak diganti.' : null),
                Select::make('user_roles')->label('Peran')->multiple()
                    ->options([User::ROLE_EMPLOYEE => 'Pegawai', User::ROLE_HEAD => 'Pimpinan unit', User::ROLE_ADMIN => 'Admin SDM'])
                    ->default([User::ROLE_EMPLOYEE])->required(),
                Toggle::make('user_is_active')->label('Aktif')->default(true)
                    ->helperText('Menonaktifkan akun juga mencabut semua token login.'),
            ]),
            Section::make('Data kepegawaian')->columns(2)->components([
                TextInput::make('nip')->label('NIP')->maxLength(30)
                    ->unique(ignoreRecord: true),
                TextInput::make('nidn')->label('NIDN')->maxLength(20),
                TextInput::make('phone')->label('No. HP')->tel()->maxLength(20),
                Select::make('employee_type')->label('Jenis pegawai')->options(EmployeeType::class)->required(),
                Select::make('unit_id')->label('Unit')->relationship('unit', 'name')->searchable()->preload(),
                Select::make('attendance_type')->label('Tipe presensi')->options(AttendanceType::class)->required()->default('fixed'),
                Select::make('work_pattern_id')->label('Pola jadwal khusus')->relationship('workPattern', 'name')->preload()
                    ->helperText('Kosongkan untuk mengikuti pola unit.'),
                TextInput::make('annual_leave_quota')->label('Jatah cuti tahunan (hari kerja)')->numeric()->minValue(0)->maxValue(365)
                    ->placeholder((string) config('attendance.leave.annual_quota_days'))
                    ->helperText('Kosongkan untuk jatah bawaan ('.config('attendance.leave.annual_quota_days').' hari).'),
            ]),
            Section::make('Lokasi absen yang diizinkan')->components([
                CheckboxList::make('offices')->hiddenLabel()->relationship('offices', 'name')->columns(3)->required()
                    ->helperText('Pegawai boleh absen masuk dan pulang di lokasi mana pun yang dicentang.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'unit', 'activeDevice']))
            ->columns([
                TextColumn::make('user.name')->label('Nama')->searchable()->sortable()
                    ->description(fn (Employee $r) => $r->user->email),
                TextColumn::make('nip')->label('NIP')->searchable()->copyable(),
                TextColumn::make('employee_type')->label('Jenis')->badge(),
                TextColumn::make('unit.name')->label('Unit')->sortable()->placeholder('-'),
                TextColumn::make('attendance_type')->label('Presensi')->badge()->color('gray'),
                TextColumn::make('activeDevice.model')->label('Perangkat')->placeholder('Belum terikat')
                    ->description(fn (Employee $r) => $r->activeDevice?->bound_at?->diffForHumans()),
                IconColumn::make('user.is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                SelectFilter::make('unit_id')->label('Unit')->options(fn () => Unit::orderBy('name')->pluck('name', 'id')->all())->searchable(),
                SelectFilter::make('employee_type')->label('Jenis')->options(EmployeeType::class),
                TernaryFilter::make('active')->label('Aktif')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('user', fn ($u) => $u->where('is_active', true)),
                        false: fn (Builder $query) => $query->whereHas('user', fn ($u) => $u->where('is_active', false)),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('resetDevice')->label('Reset Perangkat')->icon(Heroicon::OutlinedDevicePhoneMobile)->color('warning')
                    ->visible(fn (Employee $r) => auth()->user()->isAdmin() && $r->activeDevice)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Employee $r) => "Perangkat {$r->activeDevice?->model} akan dilepas dan {$r->user->name} keluar dari aplikasi. Login berikutnya akan mengikat HP baru.")
                    ->action(function (Employee $r) {
                        $r->resetDevice();
                        Notification::make()->title('Perangkat direset')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
