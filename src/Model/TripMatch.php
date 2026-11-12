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
        /** Fewer free seats on the portion than the passenger asked for. */
        public bool $full,
        /** Price of one seat from the first to the last stop of the trip. */
        public int $wholeTripPrice,
        /** Price of the cheapest single leg of the trip (« dès … MAD »). */
        public int $cheapestLegPrice,
        /** Upcoming list only: no seat is free on the whole trip, yet at least one leg still has one. */
        public bool $partlyFree,
    ) {
    }
}
