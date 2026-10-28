<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /** Pegawai dan akun login dibuat bersamaan. */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'institution_id' => auth()->user()->institution_id,
                'name' => $data['user_name'],
                'email' => $data['user_email'],
                'password' => $data['user_password'],
                'is_active' => $data['user_is_active'] ?? true,
            ]);
            $user->syncRoles($data['user_roles'] ?? [User::ROLE_EMPLOYEE]);

            return Employee::create(collect($data)->reject(fn ($v, $k) => str_starts_with($k, 'user_'))->all() + ['user_id' => $user->id]);
        });
    }
}
