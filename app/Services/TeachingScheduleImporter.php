<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Employee;
use App\Models\Office;
use App\Models\TeachingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Impor jadwal mengajar dari CSV (ekspor SIAKAD atau isian manual). Satu
 * baris = satu jadwal mingguan. Mata kuliah dibuat/diperbarui berdasarkan
 * kode; jadwal yang sama (dosen, MK, kelas, hari, jam, awal berlaku)
 * diperbarui, bukan diduplikasi. Baris yang salah dilewati dan dilaporkan.
 */
class TeachingScheduleImporter
{
    public const COLUMNS = ['nip', 'kode_mk', 'nama_mk', 'sks', 'kelas', 'hari', 'mulai', 'selesai', 'ruang', 'lokasi', 'berlaku_mulai', 'berlaku_sampai'];

    private const DAYS = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, "jum'at" => 5, 'sabtu' => 6, 'minggu' => 7];

    /** @return array{created: int, updated: int, errors: list<string>} */
    public function import(string $path, int $institutionId): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        // Excel berbahasa Indonesia menyimpan CSV dengan titik koma.
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => Str::snake(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), str_getcsv($first, $delimiter));
        $missing = array_diff(['nip', 'kode_mk', 'nama_mk', 'kelas', 'hari', 'mulai', 'selesai', 'berlaku_mulai', 'berlaku_sampai'], $header);
        if ($missing) {
            fclose($handle);
            $result['errors'][] = 'Kolom wajib tidak ada: '.implode(', ', $missing).'.';

            return $result;
        }

        $line = 1;
        while (($cells = fgetcsv($handle, null, $delimiter)) !== false) {
            $line++;
            if ($cells === [null] || ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $row = array_combine($header, array_pad(array_map(fn ($c) => trim((string) $c), $cells), count($header), ''));
            try {
                $created = DB::transaction(fn () => $this->importRow($row, $institutionId));
                $result[$created ? 'created' : 'updated']++;
            } catch (Throwable $e) {
                $result['errors'][] = "Baris $line: ".$e->getMessage();
            }
        }
        fclose($handle);

        return $result;
    }

    /** @return bool true bila jadwal baru dibuat */
    private function importRow(array $row, int $institutionId): bool
    {
        $employee = Employee::where('institution_id', $institutionId)->where('nip', $row['nip'])->first()
            ?? throw new \RuntimeException("pegawai dengan NIP {$row['nip']} tidak ditemukan.");
        $weekday = self::DAYS[Str::lower($row['hari'])] ?? (ctype_digit($row['hari']) && $row['hari'] >= 1 && $row['hari'] <= 7 ? (int) $row['hari'] : null)
            ?? throw new \RuntimeException("hari \"{$row['hari']}\" tidak dikenal.");
        $start = $this->time($row['mulai']);
        $end = $this->time($row['selesai']);
        if ($end <= $start) {
            throw new \RuntimeException('jam selesai harus setelah jam mulai.');
        }
        $from = $this->date($row['berlaku_mulai']);
        $until = $this->date($row['berlaku_sampai']);
        if ($until < $from) {
            throw new \RuntimeException('berlaku_sampai harus setelah berlaku_mulai.');
        }
        $office = null;
        if (($row['lokasi'] ?? '') !== '') {
            $office = Office::where('institution_id', $institutionId)->whereRaw('lower(name) = ?', [Str::lower($row['lokasi'])])->first()
                ?? throw new \RuntimeException("lokasi \"{$row['lokasi']}\" tidak ditemukan.");
        }

        $course = Course::updateOrCreate(
            ['institution_id' => $institutionId, 'code' => $row['kode_mk']],
            array_filter(['name' => $row['nama_mk'], 'credits' => ($row['sks'] ?? '') !== '' ? (int) $row['sks'] : null], fn ($v) => $v !== null),
        );
        $schedule = TeachingSchedule::updateOrCreate(
            [
                'institution_id' => $institutionId, 'employee_id' => $employee->id, 'course_id' => $course->id,
                'class_name' => $row['kelas'], 'weekday' => $weekday, 'start_time' => $start, 'valid_from' => $from,
            ],
            ['end_time' => $end, 'valid_until' => $until, 'room' => ($row['ruang'] ?? '') ?: null, 'office_id' => $office?->id, 'is_active' => true],
        );

        return $schedule->wasRecentlyCreated;
    }

    private function time(string $value): string
    {
        if (! preg_match('/^(\d{1,2})[:.](\d{2})$/', $value, $m) || $m[1] > 23 || $m[2] > 59) {
            throw new \RuntimeException("jam \"$value\" tidak valid (pakai HH:MM).");
        }

        return sprintf('%02d:%02d:00', $m[1], $m[2]);
    }

    private function date(string $value): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat("!$format", $value);
            } catch (Throwable) {
                continue;
            }
            if ($d->format($format) === $value) {
                return $d->toDateString();
            }
        }
        throw new \RuntimeException("tanggal \"$value\" tidak valid (pakai YYYY-MM-DD).");
    }
}
