<?php

namespace App\Model;

/** One seat of the car plan: a code like « B3 », its row (A, B or C) and a French description. */
final readonly class Seat
{
    public function __construct(
        public string $code,
        public string $row,
        public string $position,
    ) {
    }
}
