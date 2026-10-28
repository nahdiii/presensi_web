<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->record->user;

        return $data + [
            'user_name' => $user->name,
            'user_email' => $user->email,
            'user_roles' => $user->getRoleNames()->all(),
            'user_is_active' => $user->is_active,
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $user = $record->user;
            $user->fill(['name' => $data['user_name'], 'email' => $data['user_email'], 'is_active' => $data['user_is_active']]);
            if (filled($data['user_password'] ?? null)) {
                $user->password = $data['user_password'];
            }
            $user->save();
            $user->syncRoles($data['user_roles']);
            if (! $user->is_active) {
                $user->tokens()->delete();
            }
            $record->update(collect($data)->reject(fn ($v, $k) => str_starts_with($k, 'user_'))->all());

            return $record;
        });
    }
}
