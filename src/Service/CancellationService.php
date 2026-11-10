<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\CancellationNotAllowedException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Cancels bookings (by the passenger) and whole trips (by the driver), refunding the simulated payments.
 */
final class CancellationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CancellationPolicy $policy,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Cancels a booking and refunds what the policy allows at the current time.
     *
     * Cancelling after departure is allowed but refunds nothing (the policy returns 0 %).
     *
     * @throws CancellationNotAllowedException when $user is not the passenger or the booking is not confirmed
     */
    public function cancelByPassenger(Booking $booking, User $user): Payment
    {
        if ($booking->getPassenger() !== $user) {
            throw new CancellationNotAllowedException('Vous ne pouvez annuler que vos propres réservations.');
        }
        if (BookingStatus::Confirmed !== $booking->getStatus()) {
            throw new CancellationNotAllowedException('Cette réservation ne peut plus être annulée.');
        }

        $payment = $booking->getPayment()
            ?? throw new \LogicException('A confirmed booking always has a payment.');
        $now = $this->clock->now();

        $booking->setStatus(BookingStatus::CancelledByPassenger)->setCancelledAt($now);
        $payment->refund($this->policy->passengerRefundAmount($booking, $now));
        $this->em->flush();

        return $payment;
    }

    /**
     * Cancels a trip that has not started and refunds every confirmed booking in full.
     *
     * The trip row is locked like in BookingService::book(), so a booking cannot be created
     * while the driver cancels and end up confirmed on a cancelled trip.
     *
     * @return int the number of bookings cancelled
     *
     * @throws CancellationNotAllowedException when $user is not the driver, or the trip is not published or has started
     */
    public function cancelTripByDriver(Trip $trip, User $user): int
    {
        if ($trip->getDriver() !== $user) {
            throw new CancellationNotAllowedException('Seul le conducteur peut annuler ce trajet.');
        }

        return $this->em->wrapInTransaction(function () use ($trip): int {
            $this->em->lock($trip, LockMode::PESSIMISTIC_WRITE);
            // The status may have changed while we waited for the lock.
            $this->em->refresh($trip);

            $now = $this->clock->now();
            if (TripStatus::Published !== $trip->getStatus()) {
                throw new CancellationNotAllowedException('Ce trajet ne peut plus être annulé.');
            }
            if ($now >= $trip->getFirstStop()->getDepartureAt()) {
                throw new CancellationNotAllowedException('Ce trajet a déjà commencé : il ne peut plus être annulé.');
            }

            $trip->setStatus(TripStatus::Cancelled);

            $bookings = $this->em->createQuery(
                'SELECT b, p FROM App\Entity\Booking b LEFT JOIN b.payment p WHERE b.trip = :trip AND b.status = :status'
            )
                ->setParameter('trip', $trip)
                ->setParameter('status', BookingStatus::Confirmed)
                ->getResult();

            foreach ($bookings as $booking) {
                $booking->setStatus(BookingStatus::CancelledByDriver)->setCancelledAt($now);
                $booking->getPayment()?->refund($booking->getPayment()->getAmount());
            }
            $this->em->flush();

            return \count($bookings);
        });
    }
}
