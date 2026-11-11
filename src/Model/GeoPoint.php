<?php

namespace App\Model;

/** A point on Earth, in degrees. */
final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }
}
