<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Jenis pengajuan ketidakhadiran. */
enum LeaveType: string implements HasLabel
{
    case Izin = 'izin';
    case Sakit = 'sakit';
    case Cuti = 'cuti';
    case TugasLuar = 'tugas_luar';

    public function getLabel(): string
    {
        return match ($this) {
            self::Izin => 'Izin',
            self::Sakit => 'Sakit',
            self::Cuti => 'Cuti',
            self::TugasLuar => 'Tugas luar',
        };
    }

    /**
     * Boleh diajukan bersama rekan (mis. dinas dengan satu surat tugas).
     * Sakit dan cuti selalu pribadi.
     */
    public function allowsGroup(): bool
    {
        return in_array($this, [self::Izin, self::TugasLuar], true);
    }

    /** Status presensi yang ditulis untuk hari-hari selama izin disetujui. */
    public function attendanceStatus(): AttendanceStatus
    {
        return match ($this) {
            self::Sakit => AttendanceStatus::Sick,
            self::TugasLuar => AttendanceStatus::Duty,
            default => AttendanceStatus::Leave,
        };
    }
}
