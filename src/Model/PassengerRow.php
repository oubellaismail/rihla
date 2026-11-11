<?php

namespace App\Model;

use App\Entity\Booking;
use App\Entity\Review;

/** One passenger of a finished trip with the reviews exchanged with the driver. */
final readonly class PassengerRow
{
    public function __construct(
        public Booking $booking,
        public ?Review $reviewOfDriver,
        public ?Review $reviewOfPassenger,
    ) {
    }
}
