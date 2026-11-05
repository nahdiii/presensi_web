<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Concerns\BelongsToInstitution;
use App\Services\LeaveService;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Satu pengajuan untuk satu pegawai. Izin bersama membuat satu pengajuan per
 * anggota dengan group_id yang sama; submitted_by = pegawai yang mengisinya.
 */
#[Guarded(['id'])]
class LeaveRequest extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return [
            'type' => LeaveType::class,
            'status' => LeaveStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Pegawai yang mengisi pengajuan; bisa rekan dalam izin bersama. */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'submitted_by');
    }

    /** Semua pengajuan dalam izin bersama yang sama, termasuk yang ini. */
    public function groupMembers(): HasMany
    {
        return $this->hasMany(self::class, 'group_id', 'group_id')->orderBy('id');
    }

    /** Pengajuan lain yang lampirannya mirip (perlu dicek penyetuju). */
    public function similarTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'similar_leave_request_id');
    }

    /** Baris presensi yang ditulis saat pengajuan ini disetujui. */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Potongan pengajuan ini. Setelah disetujui: jumlah potongan yang benar-benar
     * tercatat di presensi (final). Selama menunggu: perkiraan hari kerja ×
     * nominal jenisnya pada aturan yang berlaku di tanggal mulai. Ditolak: 0.
     *
     * @return array{amount: int, final: bool}
     */
    public function deductionSummary(): array
    {
        return match ($this->status) {
            LeaveStatus::Approved => ['amount' => (int) $this->attendances->sum('deduction_amount'), 'final' => true],
            LeaveStatus::Rejected => ['amount' => 0, 'final' => true],
            default => ['amount' => ($this->days ?? app(LeaveService::class)->workdays(
                $this->employee, $this->start_date->toImmutable(), $this->end_date->toImmutable()))
                * (DeductionRuleSet::forDate($this->institution_id, $this->start_date->toDateString())?->leaveAmount($this->type) ?? 0),
                'final' => false],
        };
    }

    /** URL bertanda tangan untuk melihat lampiran, berlaku sebentar. */
    public function attachmentUrl(int $minutes = 10): ?string
    {
        return $this->attachment_path
            ? Storage::disk(config('attendance.photo_disk'))->temporaryUrl($this->attachment_path, now()->addMinutes($minutes))
            : null;
    }

    public function attachmentIsImage(): bool
    {
        return in_array(strtolower(pathinfo((string) $this->attachment_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
    }

    public function isSubmittedByOther(): bool
    {
        return $this->submitted_by !== null && $this->submitted_by !== $this->employee_id;
    }

    /** "3 Nov 2026" atau "3 Nov – 4 Nov 2026". */
    public function rangeLabel(): string
    {
        return $this->start_date->eq($this->end_date)
            ? $this->start_date->translatedFormat('d M Y')
            : $this->start_date->translatedFormat('d M').' – '.$this->end_date->translatedFormat('d M Y');
    }
}
