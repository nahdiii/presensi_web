<?php

namespace App\Services;

use App\Models\Office;
use Illuminate\Support\Collection;

class GeofenceService
{
    private const EARTH_RADIUS_M = 6371000;

    /** Jarak dua titik di permukaan bumi dengan rumus Haversine. */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Lokasi terdekat dari daftar, beserta jaraknya.
     *
     * @param  Collection<int, Office>  $offices
     * @return array{0: Office, 1: float}|null
     */
    public function nearest(Collection $offices, float $lat, float $lng): ?array
    {
        return $offices
            ->map(fn (Office $o) => [$o, $this->distanceMeters($lat, $lng, $o->latitude, $o->longitude)])
            ->sortBy(fn (array $pair) => $pair[1])
            ->first();
    }
}
