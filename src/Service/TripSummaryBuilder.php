<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Enum\BookingStatus;
use App\Model\PassengerRow;
use App\Model\RatingSummary;
use App\Model\TripSummary;
use App\Repository\ReviewRepository;

/** Builds the post-trip summary shown to the driver. */
final class TripSummaryBuilder
{
    public function __construct(private readonly ReviewRepository $reviews)
    {
    }

    public function build(Trip $trip): TripSummary
    {
        $driver = $trip->getDriver();
        $completed = array_values(array_filter(
            $trip->getBookings()->toArray(),
            static fn (Booking $b) => BookingStatus::Completed === $b->getStatus(),
        ));
        usort($completed, static fn (Booking $a, Booking $b) => [$a->getFromStop()->getPosition(), $a->getId()] <=> [$b->getFromStop()->getPosition(), $b->getId()]);

        $aboutDriver = [];
        $aboutPassenger = [];
        $sum = 0;
        $count = 0;
        foreach ($this->reviews->forTrip($trip) as $review) {
            $bookingId = $review->getBooking()->getId();
            if ($review->getTarget() === $driver) {
                $aboutDriver[$bookingId] = $review;
                $sum += $review->getRating();
                ++$count;
            } else {
                $aboutPassenger[$bookingId] = $review;
            }
        }

        $carried = 0;
        $amount = 0;
        $rows = [];
        foreach ($completed as $booking) {
            $carried += $booking->getSeats();
            $amount += $booking->getTotalPrice();
            $rows[] = new PassengerRow($booking, $aboutDriver[$booking->getId()] ?? null, $aboutPassenger[$booking->getId()] ?? null);
        }

        return new TripSummary($carried, $amount, new RatingSummary($count > 0 ? round($sum / $count, 1) : null, $count), $rows);
    }
}
