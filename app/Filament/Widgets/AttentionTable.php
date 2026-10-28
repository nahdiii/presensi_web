<?php

namespace App\Filament\Widgets;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PresenceCheck;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan pola untuk pimpinan unit (30 hari): sering tidak merespons cek
 * acak, berada di luar radius saat dicek, tidak absen pulang, atau alpa.
 */
class AttentionTable extends TableWidget
{
    protected static ?int $sort = 6;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Perlu perhatian · 30 hari terakhir';

    private ?array $stats = null;

    private function stats(): array
    {
        if ($this->stats !== null) {
            return $this->stats;
        }
        $since = now()->subDays(30);
        $units = auth()->user()->supervisedUnitIds();
        $scope = fn ($q) => $units === null ? $q : $q->whereHas('employee', fn ($e) => $e->whereIn('unit_id', $units));

        $checks = $scope(PresenceCheck::query())->where('scheduled_at', '>=', $since)
            ->whereIn('result', [PresenceCheck::NO_RESPONSE, PresenceCheck::OUT_OF_RADIUS])
            ->selectRaw('employee_id, result, count(*) as n')->groupBy('employee_id', 'result')->get();
        $days = $scope(Attendance::query())->whereDate('date', '>=', $since->toDateString())
            ->whereIn('status', [AttendanceStatus::Incomplete, AttendanceStatus::Absent])
            ->selectRaw('employee_id, status, count(*) as n')->groupBy('employee_id', 'status')->get();

        $stats = [];
        foreach ($checks as $r) {
            $stats[$r->employee_id][$r->result] = (int) $r->n;
        }
        foreach ($days as $r) {
            $stats[$r->employee_id][$r->status->value] = (int) $r->n;
        }
        foreach ($stats as $id => $s) {
            $stats[$id]['score'] = array_sum($s);
        }
        uasort($stats, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $this->stats = array_slice($stats, 0, 10, true);
    }

    public function table(Table $table): Table
    {
        $ids = array_keys($this->stats());
        $col = fn (string $key, string $label, string $color) => TextColumn::make($key)->label($label)->alignCenter()
            ->state(fn (Employee $e) => $this->stats()[$e->id][$key] ?? 0)
            ->color(fn ($state) => $state > 0 ? $color : 'gray')->weight(fn ($state) => $state > 0 ? 'semibold' : null);

        return $table
            ->query(fn (): Builder => Employee::query()->with(['user', 'unit'])->whereIn('id', $ids ?: [0])
                ->when($ids, fn ($query) => $query->orderByRaw('array_position(ARRAY['.implode(',', array_map('intval', $ids)).']::bigint[], id)')))
            ->paginated(false)
            ->emptyStateHeading('Tidak ada pola yang perlu diperhatikan')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->columns([
                TextColumn::make('user.name')->label('Pegawai')->weight('semibold')
                    ->description(fn (Employee $e) => $e->unit?->name),
                $col(PresenceCheck::NO_RESPONSE, 'Tak jawab cek', 'danger'),
                $col(PresenceCheck::OUT_OF_RADIUS, 'Di luar radius', 'danger'),
                $col(AttendanceStatus::Incomplete->value, 'Tak absen pulang', 'warning'),
                $col(AttendanceStatus::Absent->value, 'Alpa', 'danger'),
            ]);
    }
}
