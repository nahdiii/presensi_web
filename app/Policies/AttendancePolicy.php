<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    /** Pegawai hanya melihat presensinya sendiri; pimpinan melihat unitnya. */
    public function view(User $user, Attendance $attendance): bool
    {
        if ($user->employee?->id === $attendance->employee_id) {
            return true;
        }
        $units = $user->supervisedUnitIds();

        return $units === null || in_array($attendance->employee->unit_id, $units, true);
    }
}
