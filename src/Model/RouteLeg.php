<?php

namespace App\Model;

/** Road from one point to the next: distance, driving time and the line to draw (encoded polyline). */
final readonly class RouteLeg
{
    public function __construct(
        public int $distanceKm,
        public int $durationMin,
        public string $geometry,
    ) {
    }
}
