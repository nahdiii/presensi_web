<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\DeductionRuleSet;

/**
 * Potongan harian (rupiah) dari menit terlambat dan menit pulang cepat.
 * Tidak ada menit yang diisi tangan: keduanya dihitung dari jadwal yang
 * berlaku hari itu (AttendanceService::applyTimes), lalu dipetakan ke
 * tingkat TL/PSW dari aturan yang berlaku pada tanggal presensi.
 *
 * Dipanggil otomatis setiap kali baris presensi disimpan (Attendance::saving),
 * jadi absen, koreksi, catat manual, izin, tutup hari, dan hitung ulang
 * semuanya memakai perhitungan yang sama.
 */
class DeductionService
{
    public function apply(Attendance $a): void
    {
        $a->fill(['late_code' => null, 'early_leave_code' => null, 'deduction_amount' => 0, 'deduction_rule_set_id' => null]);

        $counted = [AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Incomplete, AttendanceStatus::Absent];
        if (! in_array($a->status, $counted, true) || ! $a->institution_id || ! $a->date) {
            return; // Izin, sakit, tugas luar, dan libur tidak dipotong.
        }
        $rules = DeductionRuleSet::forDate($a->institution_id, $a->date->toDateString());
        if (! $rules) {
            return;
        }

        $amount = 0;
        if ($a->status === AttendanceStatus::Absent) {
            $amount = $rules->absent_amount;
        } else {
            if ($tier = DeductionRuleSet::tierFor($rules->late_tiers, (int) $a->late_minutes)) {
                $a->late_code = $tier['code'];
                $amount += (int) $tier['amount'];
            }
            if ($a->status === AttendanceStatus::Incomplete) {
                $a->early_leave_code = DeductionRuleSet::NO_CHECKOUT_CODE;
                $amount += $rules->no_checkout_amount;
            } elseif ($a->check_out_at && ($tier = DeductionRuleSet::tierFor($rules->early_leave_tiers, (int) $a->early_leave_minutes))) {
                $a->early_leave_code = $tier['code'];
                $amount += (int) $tier['amount'];
            }
            // Potongan satu hari tidak melebihi potongan tidak masuk (alpa).
            if ($rules->absent_amount > 0) {
                $amount = min($amount, $rules->absent_amount);
            }
        }

        $a->fill(['deduction_amount' => $amount, 'deduction_rule_set_id' => $rules->id]);
    }
}
