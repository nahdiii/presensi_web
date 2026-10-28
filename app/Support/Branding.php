<?php

namespace App\Support;

use App\Models\Institution;

/** Nama dan inisial institusi untuk tampilan panel. */
class Branding
{
    public static function institution(): string
    {
        return once(fn () => Institution::value('name') ?? config('app.name'));
    }

    /** Dua huruf awal kata bermakna, mis. "Universitas Muhammadiyah Banjarmasin" → "UM". */
    public static function initials(): string
    {
        $words = preg_split('/\s+/', self::institution());

        return mb_strtoupper(collect($words)->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }
}
