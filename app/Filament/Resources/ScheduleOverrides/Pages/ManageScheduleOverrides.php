<?php

namespace App\Filament\Resources\ScheduleOverrides\Pages;

use App\Filament\Resources\ScheduleOverrides\ScheduleOverrideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageScheduleOverrides extends ManageRecords
{
    protected static string $resource = ScheduleOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
