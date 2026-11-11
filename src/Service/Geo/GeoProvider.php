<?php

namespace App\Service\Geo;

use App\Model\GeoPlace;

/** Address ⇄ coordinates. Never throws: an outage means "nothing found". */
interface GeoProvider
{
    /** @return list<GeoPlace> */
    public function search(string $query): array;

    public function reverse(float $lat, float $lng): ?GeoPlace;
}
