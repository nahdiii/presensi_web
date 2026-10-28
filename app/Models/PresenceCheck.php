<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cek kehadiran acak selama jam kerja. */
#[Guarded(['id'])]
class PresenceCheck extends Model
{
    use BelongsToInstitution;

    public const SCHEDULED = 'scheduled';

    public const PENDING = 'pending';

    public const OK = 'ok';

    public const OUT_OF_RADIUS = 'out_of_radius';

    public const NO_RESPONSE = 'no_response';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::SCHEDULED => 'Terjadwal', self::PENDING => 'Menunggu jawaban', self::OK => 'Di kampus',
        self::OUT_OF_RADIUS => 'Di luar radius', self::NO_RESPONSE => 'Tidak merespons',
        self::SKIPPED => 'Dilewati (izin keluar)', self::CANCELLED => 'Dibatalkan',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'deadline_at' => 'datetime',
            'responded_at' => 'datetime',
            'distance_m' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
