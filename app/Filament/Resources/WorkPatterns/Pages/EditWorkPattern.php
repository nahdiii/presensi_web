<?php

namespace App\Filament\Resources\WorkPatterns\Pages;

use App\Filament\Resources\WorkPatterns\WorkPatternResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkPattern extends EditRecord
{
    protected static string $resource = WorkPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + ['effective_from' => now(config('attendance.timezone'))->toDateString()];
    }

    protected function afterSave(): void
    {
        WorkPatternResource::recordVersion($this->record, $this->data);
    }
}
