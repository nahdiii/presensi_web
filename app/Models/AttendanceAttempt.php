<?php

namespace App\Models;

use App\Enums\RejectReason;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak audit setiap percobaan absen, termasuk yang ditolak. */
#[Guarded(['id'])]
class AttendanceAttempt extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'reason' => RejectReason::class,
            'is_mocked' => 'boolean',
            'is_rooted' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
