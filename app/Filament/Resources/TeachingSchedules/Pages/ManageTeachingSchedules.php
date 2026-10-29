<?php

namespace App\Filament\Resources\TeachingSchedules\Pages;

use App\Filament\Resources\TeachingSchedules\TeachingScheduleResource;
use App\Services\TeachingScheduleImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ManageTeachingSchedules extends ManageRecords
{
    protected static string $resource = TeachingScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')->label('Contoh CSV')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->action(fn () => response()->streamDownload(function () {
                    echo implode(',', TeachingScheduleImporter::COLUMNS)."\n";
                    echo "198805202014041002,IF201,Pemrograman Web,3,A,Senin,08:00,10:30,R. 2.04,Kampus 2 (Gedung Teknik),2026-09-01,2027-01-31\n";
                }, 'contoh-jadwal-mengajar.csv', ['Content-Type' => 'text/csv'])),
            Action::make('import')->label('Impor CSV')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->modalDescription('Kolom: '.implode(', ', TeachingScheduleImporter::COLUMNS).'. Hari boleh "Senin" atau 1–7; tanggal YYYY-MM-DD; lokasi = nama lokasi kampus (opsional). Jadwal yang sudah ada diperbarui.')
                ->schema([
                    FileUpload::make('file')->label('Berkas CSV')->disk('local')->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->required(),
                ])
                ->modalSubmitActionLabel('Impor')
                ->action(function (array $data) {
                    $disk = Storage::disk('local');
                    $result = app(TeachingScheduleImporter::class)->import($disk->path($data['file']), auth()->user()->institution_id);
                    $disk->delete($data['file']);

                    Notification::make()
                        ->title("{$result['created']} jadwal baru, {$result['updated']} diperbarui")
                        ->body($result['errors'] ? implode("\n", array_slice($result['errors'], 0, 10)).(count($result['errors']) > 10 ? "\n…" : '') : null)
                        ->{$result['errors'] ? 'warning' : 'success'}()
                        ->persistent($result['errors'] !== [])
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
