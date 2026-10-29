<?php

namespace App\Filament\Resources\TeachingSessions\Pages;

use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\TeachingSessions\TeachingSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListTeachingSessions extends ListRecords
{
    protected static string $resource = TeachingSessionResource::class;

    protected function getHeaderActions(): array
    {
        // Rekap bulanan yang sama memuat lembar sesi mengajar.
        return [ListAttendances::exportAction()];
    }
}
