<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\DeductionRuleSet;

/**
 * Potongan harian dari menit terlambat dan menit pulang cepat. Tidak ada
 * menit yang diisi tangan: keduanya dihitung dari jadwal yang berlaku hari
 * itu (AttendanceService::applyTimes), lalu dipetakan ke tingkat TL/PSW
 * dari aturan yang berlaku pada tanggal presensi.
 *
 * Dipanggil otomatis setiap kali baris presensi disimpan (Attendance::saving),
 * jadi absen, koreksi, catat manual, izin, tutup hari, dan hitung ulang
 * semuanya memakai perhitungan yang sama.
 */
class DeductionService
{
    public function apply(Attendance $a): void
    {
        $a->fill(['late_code' => null, 'early_leave_code' => null, 'deduction_percent' => 0, 'deduction_rule_set_id' => null]);

        $counted = [AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Incomplete, AttendanceStatus::Absent];
        if (! in_array($a->status, $counted, true) || ! $a->institution_id || ! $a->date) {
            return; // Izin, sakit, tugas luar, dan libur tidak dipotong.
        }
        $rules = DeductionRuleSet::forDate($a->institution_id, $a->date->toDateString());
        if (! $rules) {
            return;
        }

        $percent = 0.0;
        if ($a->status === AttendanceStatus::Absent) {
            $percent = (float) $rules->absent_percent;
        } else {
            if ($tier = DeductionRuleSet::tierFor($rules->late_tiers, (int) $a->late_minutes)) {
                $a->late_code = $tier['code'];
                $percent += (float) $tier['percent'];
            }
            if ($a->status === AttendanceStatus::Incomplete) {
                $a->early_leave_code = DeductionRuleSet::NO_CHECKOUT_CODE;
                $percent += (float) $rules->no_checkout_percent;
            } elseif ($a->check_out_at && ($tier = DeductionRuleSet::tierFor($rules->early_leave_tiers, (int) $a->early_leave_minutes))) {
                $a->early_leave_code = $tier['code'];
                $percent += (float) $tier['percent'];
            }
            // Potongan satu hari tidak melebihi potongan tidak masuk (alpa).
            if ((float) $rules->absent_percent > 0) {
                $percent = min($percent, (float) $rules->absent_percent);
            }
        }

        $a->fill(['deduction_percent' => round($percent, 2), 'deduction_rule_set_id' => $rules->id]);
    }
}
