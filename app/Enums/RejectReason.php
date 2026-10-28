<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Kode alasan penolakan absen, dikirim ke aplikasi sebagai error.code. */
enum RejectReason: string implements HasLabel
{
    case OutOfRadius = 'out_of_radius';
    case OfficeNotAllowed = 'office_not_allowed';
    case MockLocation = 'mock_location';
    case RootedDevice = 'rooted_device';
    case UnknownDevice = 'unknown_device';
    case OutsideWindow = 'outside_window';
    case LowAccuracy = 'low_accuracy';
    case NoFace = 'no_face';
    case AlreadyCheckedIn = 'already_checked_in';
    case NotCheckedIn = 'not_checked_in';
    case AlreadyCheckedOut = 'already_checked_out';
    case InactiveAccount = 'inactive_account';
    case CheckExpired = 'check_expired';
    case ExitPermitActive = 'exit_permit_active';
    case NoExitPermit = 'no_exit_permit';

    public function getLabel(): string
    {
        return match ($this) {
            self::OutOfRadius => 'Di luar radius',
            self::OfficeNotAllowed => 'Lokasi tidak diizinkan',
            self::MockLocation => 'Lokasi palsu',
            self::RootedDevice => 'Perangkat di-root',
            self::UnknownDevice => 'Perangkat tidak dikenal',
            self::OutsideWindow => 'Di luar jendela absen',
            self::LowAccuracy => 'Akurasi GPS rendah',
            self::NoFace => 'Wajah tidak valid',
            self::AlreadyCheckedIn => 'Sudah absen masuk',
            self::NotCheckedIn => 'Belum absen masuk',
            self::AlreadyCheckedOut => 'Sudah absen pulang',
            self::InactiveAccount => 'Akun nonaktif',
            self::CheckExpired => 'Cek kehadiran kedaluwarsa',
            self::ExitPermitActive => 'Masih izin keluar',
            self::NoExitPermit => 'Tidak ada izin keluar aktif',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::UnknownDevice, self::InactiveAccount => 403,
            default => 422,
        };
    }
}
