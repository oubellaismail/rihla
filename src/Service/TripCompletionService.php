<?php

namespace App\Service;

use App\Entity\Trip;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Completes published trips once their last stop's time has passed, together with their confirmed bookings.
 */
final class TripCompletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Marks every finished published trip, and its confirmed bookings, as completed.
     *
     * A trip is finished when the departure time of its last stop (the stop with the highest
     * position) is not after now. Two queries do the work whatever the number of trips:
     * one SELECT finds the finished trips (the last stop is picked with a correlated MAX(position)
     * subquery), then one bulk DQL UPDATE completes their confirmed bookings instead of
     * hydrating every booking. Cancelled bookings and cancelled trips are never touched.
     * Trips are updated through the unit of work so the loaded entities stay consistent;
     * Booking entities already in memory are not refreshed by the bulk UPDATE, which is fine
     * for a cron job that loads none. Re-running it finds nothing, so it is idempotent.
     *
     * @return int the number of trips completed
     */
    public function completeFinishedTrips(): int
    {
        return $this->em->wrapInTransaction(function (): int {
            /** @var list<Trip> $trips */
            $trips = $this->em->createQuery(
                'SELECT t FROM App\Entity\Trip t
                 JOIN t.stops last
                 WHERE t.status = :published
                   AND last.position = (SELECT MAX(s.position) FROM App\Entity\TripStop s WHERE s.trip = t)
                   AND last.departureAt <= :now'
            )
                ->setParameter('published', TripStatus::Published)
                ->setParameter('now', $this->clock->now())
                ->getResult();

            if ([] === $trips) {
                return 0;
            }

            $this->em->createQuery(
                'UPDATE App\Entity\Booking b SET b.status = :completed WHERE b.trip IN (:trips) AND b.status = :confirmed'
            )
                ->setParameter('completed', BookingStatus::Completed)
                ->setParameter('confirmed', BookingStatus::Confirmed)
                ->setParameter('trips', $trips)
                ->execute();

            foreach ($trips as $trip) {
                $trip->setStatus(TripStatus::Completed);
            }
            $this->em->flush();

            return \count($trips);
        });
    }
}
