<?php

namespace App\Filament\Resources\WorkPatterns\Pages;

use App\Filament\Resources\WorkPatterns\WorkPatternResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkPattern extends CreateRecord
{
    protected static string $resource = WorkPatternResource::class;

    protected function afterCreate(): void
    {
        // Pola baru belum dipakai presensi mana pun, jadi tidak perlu hitung ulang.
        WorkPatternResource::recordVersion($this->record, $this->data, recalculate: false);
    }
}
