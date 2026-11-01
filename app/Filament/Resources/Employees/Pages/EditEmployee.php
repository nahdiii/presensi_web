<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->record->user;

        // Data form ikut terkirim ke browser, jadi peran tidak boleh diisi untuk akun yang terkunci.
        return $data + [
            'user_name' => $user->name,
            'user_email' => $user->email,
        ] + (EmployeeResource::isAccountLocked($this->record) ? [] : [
            'user_roles' => $user->getRoleNames()->all(),
            'user_is_active' => $user->is_active,
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            if (! EmployeeResource::isAccountLocked($record)) {
                $user = $record->user;
                $user->fill(['name' => $data['user_name'], 'email' => $data['user_email'], 'is_active' => $data['user_is_active']]);
                if (filled($data['user_password'] ?? null)) {
                    $user->password = $data['user_password'];
                }
                $user->save();
                $this->syncRoles($user, $data['user_roles']);
                if (! $user->is_active) {
                    $user->tokens()->delete();
                }
            }
            $record->update(collect($data)->reject(fn ($v, $k) => str_starts_with($k, 'user_'))->all());

            return $record;
        });
    }

    /** @param  array<int, string>  $roles */
    private function syncRoles(User $user, array $roles): void
    {
        $before = $user->getRoleNames()->all();
        $user->syncRoles($roles);
        if (array_diff($before, $roles) || array_diff($roles, $before)) {
            $labels = fn (array $names) => collect($names)->map(fn (string $name) => User::ROLE_LABELS[$name])->join(', ');
            ActivityLog::record(auth()->user(), 'account', "Mengubah peran {$user->name}", ['roles' => [$labels($before), $labels($roles)]]);
        }
    }
}
