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
}
