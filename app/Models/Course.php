<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mata kuliah. Kode dipakai sebagai kunci saat impor CSV/SIAKAD. */
#[Guarded(['id'])]
class Course extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['credits' => 'integer'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TeachingSchedule::class);
    }
}
