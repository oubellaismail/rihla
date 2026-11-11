<?php

namespace App\Service\Geo;

use App\Model\GeoPoint;

/**
 * A meeting point must be in or near its stop's city: at most MAX_KM from the city centre
 * (straight line), so that a passenger looking for a city does not wait in another town.
 */
final class MeetingPointRule
{
    public const MAX_KM = 15;

    private const EARTH_RADIUS_KM = 6371.0;

    /** Haversine distance in kilometres. */
    public function distanceKm(GeoPoint $a, GeoPoint $b): float
    {
        $lat1 = deg2rad($a->latitude);
        $lat2 = deg2rad($b->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($b->longitude - $a->longitude);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }

    public function isNear(GeoPoint $meeting, GeoPoint $city): bool
    {
        return $this->distanceKm($meeting, $city) <= self::MAX_KM;
    }
}
