<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan potongan tunjangan karena terlambat (TL) dan pulang sebelum waktu
 * (PSW), berlaku mulai effective_from sampai aturan berikutnya. Seperti
 * versi jadwal, aturan tidak diubah: perubahan selalu dicatat sebagai aturan
 * baru sehingga potongan bulan lalu tetap bisa ditelusuri.
 *
 * Setiap tingkat: {code, from, to, percent}; menit di antara from dan to
 * (inklusif) kena persen itu; to kosong berarti tanpa batas atas.
 */
#[Guarded(['id'])]
class DeductionRuleSet extends Model
{
    use BelongsToInstitution;

    /** Kode untuk hari yang absen masuk tetapi tidak absen pulang. */
    public const NO_CHECKOUT_CODE = 'TAP';

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'late_tiers' => 'array',
            'early_leave_tiers' => 'array',
            'no_checkout_percent' => 'decimal:2',
            'absent_percent' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function forDate(int $institutionId, CarbonImmutable|string $date): ?self
    {
        return static::where('institution_id', $institutionId)
            ->whereDate('effective_from', '<=', CarbonImmutable::parse($date)->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();
    }

    /** @return array{code: string, from: int, to: ?int, percent: float|string}|null */
    public static function tierFor(array $tiers, int $minutes): ?array
    {
        if ($minutes <= 0) {
            return null;
        }

        return collect($tiers)->first(fn (array $t) => $minutes >= (int) $t['from']
            && ($t['to'] === null || $t['to'] === '' || $minutes <= (int) $t['to']));
    }

    /** Contoh umum TL/PSW 1–4. Sesuaikan dengan peraturan institusi. */
    public static function defaults(): array
    {
        $tiers = fn (string $p) => [
            ['code' => "{$p}1", 'from' => 1, 'to' => 30, 'percent' => 0.5],
            ['code' => "{$p}2", 'from' => 31, 'to' => 60, 'percent' => 1],
            ['code' => "{$p}3", 'from' => 61, 'to' => 90, 'percent' => 1.25],
            ['code' => "{$p}4", 'from' => 91, 'to' => null, 'percent' => 1.5],
        ];

        return [
            'late_tiers' => $tiers('TL'),
            'early_leave_tiers' => $tiers('PSW'),
            'no_checkout_percent' => 1.5,
            'absent_percent' => 5,
        ];
    }

    public function summary(): string
    {
        $fmt = fn (array $tiers) => collect($tiers)->map(fn ($t) => "{$t['code']} {$t['percent']}%")->implode(', ');

        return 'Terlambat: '.$fmt($this->late_tiers).' · Pulang cepat: '.$fmt($this->early_leave_tiers)
            .' · Tidak absen pulang: '.(float) $this->no_checkout_percent.'% · Alpa: '.(float) $this->absent_percent.'%';
    }
}
