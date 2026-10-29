<?php

namespace App\Support;

final class Rupiah
{
    /** 15000 → "Rp15.000". */
    public static function format(int|float|string|null $amount): string
    {
        return 'Rp'.number_format((int) $amount, 0, ',', '.');
    }
}
