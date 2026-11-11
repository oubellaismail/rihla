<?php

namespace App\Model;

/** A named place found by the geocoder. */
final readonly class GeoPlace
{
    public function __construct(
        public string $label,
        public float $latitude,
        public float $longitude,
    ) {
    }
}
