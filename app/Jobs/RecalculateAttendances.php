<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\RecalculationService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Hitung ulang presensi di latar belakang, lalu kabari admin yang memintanya. */
class RecalculateAttendances implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public int $institutionId,
        public string $from,
        public string $until,
        public string $reason,
        public ?array $employeeIds = null,
        public ?int $userId = null,
    ) {}

    public function handle(RecalculationService $service): void
    {
        $changed = $service->run($this->institutionId, $this->from, $this->until, $this->employeeIds);

        if ($user = User::find($this->userId)) {
            $range = CarbonImmutable::parse($this->from)->translatedFormat('d M Y')
                .($this->from === $this->until ? '' : ' – '.CarbonImmutable::parse($this->until)->translatedFormat('d M Y'));
            Notification::make()->title('Hitung ulang presensi selesai')
                ->body("{$this->reason}. Rentang $range: $changed baris presensi diperbarui.")
                ->success()->sendToDatabase($user);
        }
    }
}
