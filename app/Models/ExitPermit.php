<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Izin keluar kampus sementara di jam kerja. Selama aktif, cek acak tidak dikirim. */
#[Guarded(['id'])]
class ExitPermit extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'out_at' => 'datetime',
            'back_at' => 'datetime',
            'approved_at' => 'datetime',
            'status' => LeaveStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->back_at === null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
