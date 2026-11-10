<?php

namespace App\Service;

use App\Entity\Booking;

/**
 * Computes how many seats are taken and free on a trip, leg by leg.
 *
 * A trip is a chain of legs (leg i goes from stop i to stop i+1) and a booking
 * occupies every leg between its two stops. A seat freed at an intermediate stop
 * can be sold again for the rest of the trip, so what matters for a portion of
 * the trip is its busiest leg: that leg decides how many seats are still free.
 */
final class SeatAvailabilityCalculator
{
    /**
     * Seats taken on each leg, counting only bookings that still hold their seats.
     * Legs outside 0..$legCount-1 are ignored.
     *
     * @param iterable<Booking> $bookings
     *
     * @return list<int>
     */
    public function takenPerLeg(int $legCount, iterable $bookings): array
    {
        $taken = array_fill(0, max(0, $legCount), 0);

        foreach ($bookings as $booking) {
            if (!$booking->getStatus()->holdsSeats()) {
                continue;
            }

            $firstLeg = max(0, $booking->getFromStop()->getPosition());
            $lastLeg = min($legCount, $booking->getToStop()->getPosition()) - 1;

            for ($leg = $firstLeg; $leg <= $lastLeg; ++$leg) {
                $taken[$leg] += $booking->getSeats();
            }
        }

        return $taken;
    }

    /**
     * Seats still free for a passenger travelling from stop $fromPosition to stop $toPosition:
     * the seats offered minus those taken on the busiest leg of that portion, never below 0.
     *
     * @param iterable<Booking> $bookings
     *
     * @throws \InvalidArgumentException if the portion is empty, reversed or starts before the first stop
     */
    public function freeSeats(int $seatsOffered, iterable $bookings, int $fromPosition, int $toPosition): int
    {
        if ($fromPosition < 0 || $fromPosition >= $toPosition) {
            throw new \InvalidArgumentException(sprintf('Invalid portion: from %d to %d.', $fromPosition, $toPosition));
        }

        $taken = $this->takenPerLeg($toPosition, $bookings);
        $busiest = max(array_slice($taken, $fromPosition, $toPosition - $fromPosition));

        return max(0, $seatsOffered - $busiest);
    }
}
