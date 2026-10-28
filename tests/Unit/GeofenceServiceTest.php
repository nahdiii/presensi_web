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

    public function test_nearest_office(): void
    {
        $g = new GeofenceService;
        $a = (new Office)->forceFill(['name' => 'A', 'latitude' => -3.3152, 'longitude' => 114.5883]);
        $b = (new Office)->forceFill(['name' => 'B', 'latitude' => -3.2985, 'longitude' => 114.6012]);

        [$office, $distance] = $g->nearest(collect([$a, $b]), -3.2990, 114.6012);
        $this->assertSame('B', $office->name);
        $this->assertEqualsWithDelta(55.6, $distance, 1);
        $this->assertNull($g->nearest(collect(), 0, 0));
    }
}
