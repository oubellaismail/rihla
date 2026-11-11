<?php

namespace App\Model;

/** What the driver sees once a trip is over. */
final readonly class TripSummary
{
    /**
     * @param list<PassengerRow> $passengers completed bookings in boarding order
     */
    public function __construct(
        public int $passengersCarried,
        public int $amountReceived,
        public RatingSummary $rating,
        public array $passengers,
    ) {
    }
}
