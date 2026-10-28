<?php

namespace App\Models;

use App\Enums\WorkPatternType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class WorkPattern extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['type' => WorkPatternType::class];
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkPatternDay::class)->orderBy('weekday');
    }

    public function dayFor(int $isoWeekday): ?WorkPatternDay
    {
        return $this->days->firstWhere('weekday', $isoWeekday);
    }
}
