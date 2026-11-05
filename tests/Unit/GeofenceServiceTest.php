<?php

namespace Tests\Unit;

use App\Models\Office;
use App\Services\GeofenceService;
use PHPUnit\Framework\TestCase;

class GeofenceServiceTest extends TestCase
{
    public function test_haversine_distance(): void
    {
        $g = new GeofenceService;

        $this->assertSame(0.0, $g->distanceMeters(-3.3152, 114.5883, -3.3152, 114.5883));
        // 0,001° lintang ≈ 111,2 m
        $this->assertEqualsWithDelta(111.2, $g->distanceMeters(-3.3152, 114.5883, -3.3162, 114.5883), 0.5);
        // Banjarmasin → Banjarbaru ≈ 30 km
        $this->assertEqualsWithDelta(30_000, $g->distanceMeters(-3.3194, 114.5908, -3.4425, 114.8292), 1_500);
    }

    public function test_falls_back_to_nearest_office(): void
    {
        $g = new GeofenceService;
        $a = (new Office)->forceFill(['name' => 'A', 'latitude' => -3.3152, 'longitude' => 114.5883]);
        $b = (new Office)->forceFill(['name' => 'B', 'latitude' => -3.2985, 'longitude' => 114.6012]);

        // Tidak ada radius yang memuat titik: kembali ke lokasi terdekat.
        [$office, $distance] = $g->locate(collect([$a, $b]), -3.2990, 114.6012);
        $this->assertSame('B', $office->name);
        $this->assertEqualsWithDelta(55.6, $distance, 1);
        $this->assertNull($g->locate(collect(), 0, 0));
    }

    public function test_office_whose_radius_contains_point_wins_over_nearer_centre(): void
    {
        $g = new GeofenceService;
        $building = (new Office)->forceFill(['name' => 'Gedung', 'latitude' => -3.2990, 'longitude' => 114.6000, 'radius_m' => 50]);
        $campus = (new Office)->forceFill(['name' => 'Kampus', 'latitude' => -3.3000, 'longitude' => 114.6000, 'radius_m' => 400]);

        // ±83 m dari pusat gedung (di luar 50 m), ±195 m dari pusat kampus (di dalam 400 m).
        [$office, $distance] = $g->locate(collect([$building, $campus]), -3.29825, 114.6000);
        $this->assertSame('Kampus', $office->name);
        $this->assertEqualsWithDelta(194.6, $distance, 1);

        // Di dalam keduanya: yang terdekat.
        $this->assertSame('Gedung', $g->locate(collect([$building, $campus]), -3.2991, 114.6000)[0]->name);
    }
}
