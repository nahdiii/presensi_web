<?php

namespace App\Console\Commands;

use App\Services\PresenceCheckService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('presence-checks:dispatch')]
#[Description('Kirim cek kehadiran acak yang sudah waktunya dan tandai yang tidak dijawab')]
class DispatchPresenceChecks extends Command
{
    public function handle(PresenceCheckService $checks): int
    {
        $counts = $checks->dispatchDue();
        if (array_sum($counts)) {
            $this->line(collect($counts)->map(fn ($n, $k) => "$k: $n")->implode(', '));
        }

        return self::SUCCESS;
    }
}
