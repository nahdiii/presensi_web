<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Satu aktivitas pengguna: siapa, melakukan apa, dan kapan. */
#[Guarded(['id'])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'login' => 'Masuk',
        'logout' => 'Keluar',
        'attendance' => 'Presensi',
        'request' => 'Pengajuan',
        'review' => 'Persetujuan',
        'account' => 'Akun',
        'data' => 'Ubah data',
        'report' => 'Laporan',
    ];

    private const FIELD_LABELS = [
        'name' => 'Nama',
        'email' => 'Email',
        'password' => 'Password',
        'is_active' => 'Aktif',
        'roles' => 'Peran',
        'nip' => 'NIP',
        'nidn' => 'NIDN',
        'phone' => 'No. HP',
        'unit_id' => 'Unit',
        'employee_type' => 'Jenis pegawai',
        'attendance_type' => 'Tipe presensi',
        'work_pattern_id' => 'Pola jadwal',
        'annual_leave_quota' => 'Jatah cuti',
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [lama, baru] */
    public static function record(User $user, string $action, string $description, array $changes = []): self
    {
        return static::create([
            'institution_id' => $user->institution_id,
            'user_id' => $user->id,
            'action' => $action,
            'description' => $description,
            'details' => $changes ?: null,
            'ip' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 250, '') ?: null,
        ]);
    }

    /**
     * Setiap orang melihat log sendiri. Admin SDM juga melihat pegawai dan
     * pimpinan unit, bukan sesama admin; Super Admin melihat semuanya.
     * Peran tertinggi yang berlaku, jadi pegawai yang juga Super Admin tidak
     * terlihat oleh Admin SDM.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if ($viewer->isSuperAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query->where('user_id', $viewer->id)
            ->when($viewer->isAdmin(), fn (Builder $query) => $query->orWhereHas('user',
                fn (Builder $query) => $query->whereDoesntHave('roles',
                    fn (Builder $query) => $query->whereIn('name', [User::ROLE_SUPER, User::ROLE_ADMIN])))));
    }

    /** @return array<int, string> */
    public function changeLines(): array
    {
        $show = fn (mixed $value) => is_bool($value) ? ($value ? 'Ya' : 'Tidak') : ($value ?? '-');

        return collect($this->details ?? [])
            ->map(fn (array $pair, string $field) => (self::FIELD_LABELS[$field] ?? Str::headline($field)).': '.$show($pair[0]).' → '.$show($pair[1]))
            ->values()->all();
    }
}
