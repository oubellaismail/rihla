<?php

namespace App\Service;

use App\Entity\Booking;

/**
 * Refund rules applied when a passenger cancels a booking.
 *
 * The refund depends on the departure time of the passenger's own boarding stop,
 * not on the first stop of the trip. Time is always passed in, never read here.
 */
final class CancellationPolicy
{
    /** Hours before departure up to which (inclusive) the refund is full. */
    public const FULL_REFUND_HOURS = 24;

    public const FULL_REFUND_PERCENT = 100;
    public const PARTIAL_REFUND_PERCENT = 50;
    public const NO_REFUND_PERCENT = 0;

    /**
     * Refund percentage (100, 50 or 0) for a passenger cancelling at $now.
     */
    public function passengerRefundPercent(Booking $booking, \DateTimeImmutable $now): int
    {
        $departureAt = $booking->getFromStop()->getDepartureAt();

        if ($now >= $departureAt) {
            return self::NO_REFUND_PERCENT;
        }

        if ($now <= $departureAt->modify(sprintf('-%d hours', self::FULL_REFUND_HOURS))) {
            return self::FULL_REFUND_PERCENT;
        }

        return self::PARTIAL_REFUND_PERCENT;
    }

    /**
     * Refund amount in whole MAD, rounded to the nearest unit (half rounds up).
     */
    public function passengerRefundAmount(Booking $booking, \DateTimeImmutable $now): int
    {
        $percent = $this->passengerRefundPercent($booking, $now);

        return intdiv($booking->getTotalPrice() * $percent + 50, 100);
    }
}
