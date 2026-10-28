<?php

namespace App\Filament\Resources\AttendanceAttempts\Pages;

use App\Filament\Resources\AttendanceAttempts\AttendanceAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceAttempts extends ListRecords
{
    protected static string $resource = AttendanceAttemptResource::class;
}
