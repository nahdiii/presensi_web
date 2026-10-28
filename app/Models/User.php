<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['institution_id', 'name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    public const ROLE_ADMIN = 'admin_sdm';

    public const ROLE_HEAD = 'pimpinan_unit';

    public const ROLE_EMPLOYEE = 'pegawai';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyRole([self::ROLE_ADMIN, self::ROLE_HEAD]);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    /**
     * Unit yang boleh dipantau pimpinan: unit yang ia kepalai beserta
     * turunannya. Admin SDM melihat semuanya (null).
     *
     * @return array<int>|null
     */
    public function supervisedUnitIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        $employee = $this->employee;
        if (! $employee) {
            return [];
        }

        return Unit::where('head_employee_id', $employee->id)->get()
            ->flatMap(fn (Unit $u) => $u->descendantIds())
            ->unique()->values()->all();
    }
}
