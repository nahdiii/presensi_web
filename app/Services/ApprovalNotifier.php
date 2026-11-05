<?php

namespace App\Services;

use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\ExitPermits\ExitPermitResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\ExitPermit;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Memberi tahu yang berwenang menyetujui saat ada pengajuan baru: lonceng
 * di panel admin dan push ke HP. Penerimanya kepala unit terdekat di atas
 * pegawai (bukan dirinya sendiri); bila tidak ada, Admin SDM.
 */
class ApprovalNotifier
{
    public function __construct(private readonly PushNotifier $push) {}

    public function leaveSubmitted(LeaveRequest $leave): void
    {
        $this->leavesSubmitted(collect([$leave]));
    }

    /**
     * Pengajuan baru, termasuk izin bersama: anggota yang penyetujunya sama
     * digabung dalam satu notifikasi supaya kepala unit tidak menerima satu
     * pesan per anggota.
     *
     * @param  Collection<int, LeaveRequest>  $leaves
     */
    public function leavesSubmitted(Collection $leaves): void
    {
        $first = $leaves->first();
        $byApprovers = $leaves->groupBy(fn (LeaveRequest $l) => $this->approversFor($l->employee->loadMissing(['user', 'unit']))
            ->pluck('id')->sort()->implode(','));

        foreach ($byApprovers as $group) {
            $names = $group->map(fn (LeaveRequest $l) => $l->employee->name);
            $who = $names->take(3)->implode(', ').($names->count() > 3 ? ' +'.($names->count() - 3) : '');
            $days = $group->count() === 1 && $first->days ? " ({$first->days} hari kerja)" : '';
            $by = $first->submitter && $group->doesntContain(fn (LeaveRequest $l) => $l->employee_id === $first->submitted_by)
                ? ", diajukan oleh {$first->submitter->name}" : '';
            $this->notify($this->approversFor($group->first()->employee),
                "Pengajuan {$first->typeLabel()}".($leaves->count() > 1 ? ' bersama' : ''),
                "$who · {$first->rangeLabel()}$days$by",
                LeaveRequestResource::getUrl(panel: 'admin'), Heroicon::OutlinedDocumentText);
        }
    }

    public function correctionSubmitted(AttendanceCorrection $c): void
    {
        $this->send($c->employee, 'Koreksi lupa absen',
            "{$c->employee->name} · {$c->date->translatedFormat('d M Y')}",
            AttendanceCorrectionResource::getUrl(panel: 'admin'), Heroicon::OutlinedPencilSquare);
    }

    public function exitPermitStarted(ExitPermit $permit): void
    {
        $this->send($permit->employee, 'Izin keluar kampus',
            "{$permit->employee->name} keluar pukul ".$permit->out_at->setTimezone(config('attendance.timezone'))->format('H:i').": {$permit->reason}",
            ExitPermitResource::getUrl(panel: 'admin'), Heroicon::OutlinedArrowRightStartOnRectangle);
    }

    /** @return Collection<int, User> */
    public function approversFor(Employee $employee): Collection
    {
        foreach ($employee->unit?->lineage() ?? [] as $unit) {
            if ($unit->head_employee_id && $unit->head_employee_id !== $employee->id) {
                $head = $unit->head?->user;
                if ($head?->is_active) {
                    return collect([$head]);
                }
            }
        }

        // whereHas, bukan User::role(): yang terakhir melempar exception bila role belum dibuat.
        return User::whereHas('roles', fn ($query) => $query->where('name', User::ROLE_ADMIN))->where('is_active', true)
            ->where('institution_id', $employee->institution_id)->get();
    }

    private function send(Employee $from, string $title, string $body, string $url, Heroicon $icon): void
    {
        $this->notify($this->approversFor($from->loadMissing(['user', 'unit'])), $title, $body, $url, $icon);
    }

    /** @param  Collection<int, User>  $approvers */
    private function notify(Collection $approvers, string $title, string $body, string $url, Heroicon $icon): void
    {
        Notification::make()->title($title)->body($body)->icon($icon)->iconColor('warning')
            ->actions([Action::make('open')->label('Tinjau')->url($url)->markAsRead()])
            ->sendToDatabase($approvers);

        foreach ($approvers as $user) {
            if ($user->employee) {
                $this->push->send($user->employee, $title, $body.'. Tinjau di panel admin.', ['type' => 'approval']);
            }
        }
    }
}
