<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToInstitution;
use App\Services\DeductionService;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris per pegawai per hari. */
#[Guarded(['id'])]
class Attendance extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
            'schedule_snapshot' => 'array',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_lat' => 'float',
            'check_in_lng' => 'float',
            'check_out_lat' => 'float',
            'check_out_lng' => 'float',
            'check_in_distance_m' => 'float',
            'check_out_distance_m' => 'float',
            'check_in_accuracy_m' => 'float',
            'check_out_accuracy_m' => 'float',
            'is_early_leave' => 'boolean',
            'early_leave_minutes' => 'integer',
            'deduction_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Potongan selalu mengikuti isi baris terakhir; tidak ada yang mengisinya tangan.
        static::saving(function (Attendance $a) {
            $a->institution_id ??= $a->employee?->institution_id;
            app(DeductionService::class)->apply($a);
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function checkInOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'check_in_office_id');
    }

    public function checkOutOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'check_out_office_id');
    }

    public function deductionRuleSet(): BelongsTo
    {
        return $this->belongsTo(DeductionRuleSet::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Pengajuan izin/sakit/cuti/tugas luar yang menjadi dasar baris ini. */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function isLeaveDay(): bool
    {
        return in_array($this->status, [AttendanceStatus::Leave, AttendanceStatus::Sick, AttendanceStatus::Duty], true);
    }

    /** Nama jenis izin untuk baris izin/sakit/tugas luar, mis. "Cuti melahirkan". */
    public function leaveLabel(): ?string
    {
        return $this->isLeaveDay() ? ($this->leaveRequest?->typeLabel() ?? $this->status->getLabel()) : null;
    }

    /**
     * Kode/sebab potongan hari ini, mis. ["TL2", "PSW1"], ["Alpa"], ["Cuti"].
     *
     * @return list<string>
     */
    public function deductionCodes(): array
    {
        if ($this->deduction_amount <= 0) {
            return [];
        }

        return array_values(array_filter([
            $this->late_code,
            $this->early_leave_code,
            $this->status === AttendanceStatus::Absent ? 'Alpa' : null,
            $this->leaveLabel(),
        ]));
    }
}
