<?php

namespace App\Models;

use App\Enums\CalendarEventType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
class CalendarEvent extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['date' => 'date', 'type' => CalendarEventType::class];
    }
}
