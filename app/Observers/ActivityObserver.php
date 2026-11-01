<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\CalendarEvent;
use App\Models\DeductionRuleSet;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleOverride;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkPatternVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Mencatat perubahan data induk oleh pengguna yang sedang login. Pekerjaan
 * terjadwal tidak punya pengguna, jadi tidak ikut tercatat.
 */
class ActivityObserver
{
    public const SUBJECTS = [
        Employee::class => 'data pegawai',
        User::class => 'akun',
        Unit::class => 'unit',
        Office::class => 'lokasi',
        WorkPattern::class => 'pola jadwal',
        WorkPatternVersion::class => 'versi pola jadwal',
        DeductionRuleSet::class => 'aturan potongan',
        ScheduleOverride::class => 'jadwal khusus',
        CalendarEvent::class => 'kalender',
    ];

    private const IGNORED = ['created_at', 'updated_at', 'remember_token', 'photo_path'];

    public function created(Model $model): void
    {
        // Akun selalu dibuat bersama data pegawainya.
        if (! $model instanceof User) {
            $this->record('Menambah', $model);
        }
    }

    public function updated(Model $model): void
    {
        $new = Arr::except($model->getChanges(), self::IGNORED);
        if (! $new) {
            return;
        }
        $changes = [];
        foreach ($new as $field => $value) {
            // Atribut tersembunyi seperti password hanya dicatat bahwa ia berubah.
            $changes[$field] = in_array($field, $model->getHidden(), true)
                ? ['••••', '••••']
                : [$model->getPrevious()[$field] ?? null, $value];
        }
        if ($model instanceof User && array_keys($new) === ['password'] && auth()->id() === $model->id) {
            ActivityLog::record($model, 'account', 'Mengganti password');

            return;
        }
        $this->record('Mengubah', $model, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->record('Menghapus', $model);
    }

    private function record(string $verb, Model $model, array $changes = []): void
    {
        $user = auth()->user();
        if ($user instanceof User) {
            $title = $model->name ?? $model->reason ?? $model->note ?? '#'.$model->getKey();
            ActivityLog::record($user, 'data', $verb.' '.self::SUBJECTS[$model::class].' '.$title, $changes);
        }
    }
}
