<?php

namespace App\Filament\Resources\LeaveTypes;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\LeaveTypes\Pages\ManageLeaveTypes;
use App\Models\LeaveType;
use App\Support\Rupiah;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Master jenis izin: nama, potongan per hari kerja, jatah, dan aturan
 * pengajuan. Potongan disalin ke pengajuan saat diajukan, jadi mengubahnya
 * di sini hanya berlaku untuk pengajuan baru.
 */
class LeaveTypeResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = LeaveType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'jenis izin';

    protected static ?string $pluralModelLabel = 'Jenis Izin';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(100)->placeholder('mis. Cuti melahirkan'),
            TextInput::make('code')->label('Kode')->required()->maxLength(30)
                ->regex('/^[a-z0-9_]+$/')->placeholder('mis. cuti_melahirkan')
                ->helperText('Huruf kecil, angka, dan garis bawah. Tidak bisa diubah setelah disimpan.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('institution_id', auth()->user()->institution_id))
                ->disabledOn('edit'),
            Select::make('attendance_status')->label('Dicatat di presensi sebagai')->required()
                ->options(LeaveType::attendanceStatusOptions())->default('leave')
                ->helperText('Menentukan kolom rekap: Izin/Cuti, Sakit, atau Tugas luar.'),
            TextInput::make('sort')->label('Urutan')->numeric()->integer()->minValue(0)->default(fn () => (LeaveType::max('sort') ?? 0) + 1),
            Section::make('Potongan dan jatah')->columns(2)->columnSpanFull()->components([
                TextInput::make('deduction_per_day')->label('Potongan per hari kerja')->required()
                    ->numeric()->integer()->minValue(0)->prefix('Rp')->default(0)
                    ->helperText('Isi 0 bila tidak dipotong. Perubahan hanya berlaku untuk pengajuan baru; '
                        .'pengajuan yang sudah dibuat tetap memakai nominal saat diajukan.'),
                TextInput::make('annual_quota_days')->label('Jatah per tahun (hari kerja)')
                    ->numeric()->integer()->minValue(1)->maxValue(365)->placeholder('Tanpa jatah')
                    ->helperText(fn (?LeaveType $record) => $record?->code === LeaveType::ANNUAL_LEAVE
                        ? 'Jatah cuti di form Pegawai, bila diisi, menggantikan angka ini.'
                        : 'Kosongkan bila jenis ini tidak dibatasi jatah.'),
            ]),
            Section::make('Aturan pengajuan')->columns(3)->columnSpanFull()->components([
                Toggle::make('allows_group')->label('Boleh diajukan bersama rekan')
                    ->helperText('mis. tugas luar dengan satu surat tugas'),
                Toggle::make('requires_attachment')->label('Wajib lampiran')
                    ->helperText('mis. surat dokter'),
                Toggle::make('is_active')->label('Aktif')->default(true)
                    ->helperText('Nonaktif: tidak bisa diajukan lagi, riwayatnya tetap ada.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('leaveRequests'))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('semibold')->searchable()
                    ->description(fn (LeaveType $r) => $r->code),
                TextColumn::make('attendance_status')->label('Dicatat sebagai')->badge(),
                TextColumn::make('deduction_per_day')->label('Potongan/hari')
                    ->formatStateUsing(fn ($state) => $state > 0 ? Rupiah::format($state) : 'Tidak dipotong')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('annual_quota_days')->label('Jatah/tahun')->suffix(' hari')->placeholder('-'),
                IconColumn::make('allows_group')->label('Bersama')->boolean(),
                IconColumn::make('requires_attachment')->label('Wajib lampiran')->boolean(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('leave_requests_count')->label('Pengajuan'),
            ])
            ->recordActions([
                EditAction::make(),
                // Jenis yang sudah dipakai tidak bisa dihapus (riwayat merujuknya); nonaktifkan saja.
                DeleteAction::make()->visible(fn (LeaveType $r) => $r->leave_requests_count === 0),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLeaveTypes::route('/')];
    }
}
