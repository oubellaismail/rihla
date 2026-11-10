<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;

/**
 * Decides who may review whom after a completed trip: the passenger reviews
 * the driver and the driver reviews the passenger, once each, within a time window.
 */
final class ReviewPolicy
{
    /** How long after the passenger's drop-off a review stays possible (inclusive). */
    private const string REVIEW_WINDOW = 'P14D';

    /** Whether $author may still review the other party of this booking. */
    public function canReview(Booking $booking, User $author, \DateTimeImmutable $now, bool $alreadyReviewed): bool
    {
        if (BookingStatus::Completed !== $booking->getStatus() || $alreadyReviewed) {
            return false;
        }
        if (!$this->isParticipant($booking, $author)) {
            return false;
        }

        $deadline = $booking->getToStop()->getDepartureAt()->add(new \DateInterval(self::REVIEW_WINDOW));

        return $now <= $deadline;
    }

    /**
     * The user $author is reviewing: the driver for the passenger, the passenger for the driver.
     *
     * @throws \InvalidArgumentException when $author is not part of the booking
     */
    public function targetFor(Booking $booking, User $author): User
    {
        $driver = $booking->getTrip()->getDriver();
        $passenger = $booking->getPassenger();

        return match (true) {
            $author === $passenger => $driver,
            $author === $driver => $passenger,
            default => throw new \InvalidArgumentException('Only the passenger or the driver of a booking can review it.'),
        };
    }

    /** Whether $user is the booking's passenger or the trip's driver. */
    private function isParticipant(Booking $booking, User $user): bool
    {
        return $user === $booking->getPassenger() || $user === $booking->getTrip()->getDriver();
    }
}
