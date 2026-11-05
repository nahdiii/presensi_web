<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lebar kolom dihitung dari panjang teks, bukan ShouldAutoSize: autosize
 * PhpSpreadsheet mengukur setiap sel dan membuat rekap besar dua kali lebih lama.
 */
class ArraySheet implements FromArray, WithColumnWidths, WithFreezePane, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly string $title, private readonly array $headings, private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function columnWidths(): array
    {
        $widths = array_map(fn ($h) => mb_strlen((string) $h), $this->headings);
        foreach ($this->rows as $row) {
            foreach (array_values($row) as $i => $value) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $value));
            }
        }

        $columns = [];
        foreach ($widths as $i => $w) {
            $columns[Coordinate::stringFromColumnIndex($i + 1)] = min(max($w, 6), 50) + 2;
        }

        return $columns;
    }

    public function freezePane(): string
    {
        return 'A2';
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
