<?php

namespace App\Models;

use App\Enums\LeaveStatus;
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
            'deduction_per_day' => 'integer',
            'cancelled_dates' => 'array',
            'status' => LeaveStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LeaveRequest $l) {
            // Kolom type menyimpan kode jenis (mis. "cuti") untuk API dan data lama.
            if (! $l->leave_type_id && $l->type) {
                $l->leave_type_id = LeaveType::findCode($l->institution_id ?? $l->employee->institution_id, $l->type)?->id;
            }
            if ($l->isDirty('leave_type_id') && $l->leaveType) {
                $l->type = $l->leaveType->code;
            }
        });
        // Potongan per hari disalin saat diajukan; perubahan master tidak mengubahnya.
        static::creating(function (LeaveRequest $l) {
            if (! array_key_exists('deduction_per_day', $l->getAttributes())) {
                $l->deduction_per_day = $l->leaveType?->deduction_per_day ?? 0;
            }
        });
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** Nama jenis, mis. "Cuti melahirkan". */
    public function typeLabel(): string
    {
        return $this->leaveType?->name ?? (string) $this->type;
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
     * potongan per hari yang disalin saat diajukan. Ditolak: 0.
     *
     * @return array{amount: int, final: bool}
     */
    public function deductionSummary(): array
    {
        return match ($this->status) {
            LeaveStatus::Approved => ['amount' => (int) $this->attendances->sum('deduction_amount'), 'final' => true],
            LeaveStatus::Rejected => ['amount' => 0, 'final' => true],
            default => ['amount' => ($this->days ?? app(LeaveService::class)->workdays(
                $this->employee, $this->start_date->toImmutable(), $this->end_date->toImmutable())) * $this->deduction_per_day,
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

    /**
     * Pengajuan yang sudah disetujui dan mencakup tanggal ini, kecuali hari
     * yang dibatalkan karena pegawai masuk kerja.
     */
    public static function approvedOn(Employee $employee, string $date): ?self
    {
        return self::with('leaveType')->where('employee_id', $employee->id)->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->where(fn ($query) => $query->whereNull('cancelled_dates')->orWhereJsonDoesntContain('cancelled_dates', $date))
            ->first();
    }

    public function isCancelledOn(string $date): bool
    {
        return in_array($date, $this->cancelled_dates ?? [], true);
    }

    /** Pesan untuk pegawai yang mencoba absen pada hari izinnya. */
    public function onLeaveMessage(): string
    {
        return "Anda sedang {$this->typeLabel()} ({$this->rangeLabel()}) yang sudah disetujui. "
            .'Bila hari ini Anda masuk kerja, batalkan izin hari ini di beranda lalu absen.';
    }

    /** "3 Nov 2026" atau "3 Nov – 4 Nov 2026". */
    public function rangeLabel(): string
    {
        return $this->start_date->eq($this->end_date)
            ? $this->start_date->translatedFormat('d M Y')
            : $this->start_date->translatedFormat('d M').' – '.$this->end_date->translatedFormat('d M Y');
    }
}
