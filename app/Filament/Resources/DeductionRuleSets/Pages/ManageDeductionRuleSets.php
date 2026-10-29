<?php

namespace App\Filament\Resources\DeductionRuleSets\Pages;

use App\Filament\Resources\DeductionRuleSets\DeductionRuleSetResource;
use App\Jobs\RecalculateAttendances;
use App\Models\DeductionRuleSet;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageDeductionRuleSets extends ManageRecords
{
    protected static string $resource = DeductionRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat aturan baru')
                ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])
                ->after(function (DeductionRuleSet $record) {
                    $today = now(config('attendance.timezone'))->toDateString();
                    $from = $record->effective_from->toDateString();
                    if ($from <= $today) {
                        RecalculateAttendances::dispatch($record->institution_id, $from, $today, 'Aturan potongan baru', userId: auth()->id())
                            ->afterCommit();
                        Notification::make()->title('Potongan sejak '.$record->effective_from->translatedFormat('d M Y').' sedang dihitung ulang')
                            ->body('Anda akan diberi tahu bila sudah selesai.')->info()->send();
                    }
                }),
        ];
    }
}
