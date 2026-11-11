<?php

namespace App\Service\Geo;

use App\Model\GeoPoint;
use App\Model\RouteLeg;

/** Road distance, time and line between points. Never throws: an outage means null. */
interface RouteProvider
{
    public function leg(GeoPoint $from, GeoPoint $to): ?RouteLeg;

    /**
     * @param list<GeoPoint> $points at least two
     *
     * @return list<RouteLeg>|null null if any leg could not be computed
     *
     * @throws \InvalidArgumentException with fewer than two points
     */
    public function route(array $points): ?array;
}
