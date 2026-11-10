<?php

namespace App\Model;

use App\Entity\Trip;
use App\Entity\TripStop;

/**
 * A trip that serves the searched portion, with what the passenger needs to decide.
 */
final readonly class TripMatch
{
    public function __construct(
        public Trip $trip,
        public TripStop $from,
        public TripStop $to,
        public int $freeSeats,
        public int $pricePerSeat,
    ) {
    }
}
