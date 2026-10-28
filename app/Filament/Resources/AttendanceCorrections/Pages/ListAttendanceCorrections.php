<?php

namespace App\Filament\Resources\AttendanceCorrections\Pages;

use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceCorrections extends ListRecords
{
    protected static string $resource = AttendanceCorrectionResource::class;
}
