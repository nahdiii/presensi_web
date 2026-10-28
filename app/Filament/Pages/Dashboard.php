<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Attendances\Pages\ListAttendances;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dasbor';

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }

    public function getSubheading(): string
    {
        return 'Ringkasan kehadiran sesuai cakupan akses Anda: '.$this->scopeLabel().'.';
    }

    public function scopeLabel(): string
    {
        $user = auth()->user();
        if ($user->isAdmin()) {
            return 'seluruh unit';
        }
        $units = \App\Models\Unit::where('head_employee_id', $user->employee?->id)->pluck('name');

        return $units->isEmpty() ? 'tidak ada unit' : $units->implode(', ');
    }

    protected function getHeaderActions(): array
    {
        return [ListAttendances::exportAction()];
    }
}
