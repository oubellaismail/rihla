<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Model\TripMatch;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Finds the published trips that carry a passenger from one city to another on a given day.
 */
final class TripSearch
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeatAvailabilityCalculator $seatAvailability,
        private readonly PriceCalculator $priceCalculator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<TripMatch> sorted by departure time from the boarding city
     *
     * @throws \InvalidArgumentException when fewer than one seat is requested
     */
    public function search(City $from, City $to, \DateTimeImmutable $date, int $seats = 1): array
    {
        if ($seats < 1) {
            throw new \InvalidArgumentException('Il faut chercher au moins une place.');
        }

        $dayStart = $date->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $now = $this->clock->now();

        /** @var list<array{fromId: int, toId: int}> $rows */
        $rows = $this->em->createQuery(
            'SELECT f.id AS fromId, t.id AS toId FROM '.TripStop::class.' f
             JOIN f.trip trip
             JOIN '.TripStop::class.' t WITH t.trip = f.trip AND t.position > f.position
             WHERE f.city = :from AND t.city = :to
               AND trip.status = :status
               AND f.departureAt >= :dayStart AND f.departureAt < :dayEnd AND f.departureAt > :now
             ORDER BY f.departureAt ASC, f.position ASC, t.position ASC'
        )
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', TripStatus::Published)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd)
            ->setParameter('now', $now)
            ->getScalarResult();

        if ([] === $rows) {
            return [];
        }

        $stops = $this->loadStops($rows);

        // Rows come sorted by departure time; keep the first arrival stop of each trip.
        $pairs = [];
        foreach ($rows as $row) {
            $fromStop = $stops[$row['fromId']];
            $tripId = $fromStop->getTrip()->getId();
            if (!isset($pairs[$tripId])) {
                $pairs[$tripId] = [$fromStop, $stops[$row['toId']]];
            }
        }
        $pairs = array_values($pairs);

        $bookingsByTrip = $this->loadBookings(array_map(static fn (array $p): Trip => $p[0]->getTrip(), $pairs));

        $matches = [];
        foreach ($pairs as [$fromStop, $toStop]) {
            $trip = $fromStop->getTrip();
            $freeSeats = $this->seatAvailability->freeSeats(
                $trip->getSeatsOffered(),
                $bookingsByTrip[$trip->getId()] ?? [],
                $fromStop->getPosition(),
                $toStop->getPosition(),
            );
            if ($freeSeats < $seats) {
                continue;
            }

            $matches[] = new TripMatch(
                $trip,
                $fromStop,
                $toStop,
                $freeSeats,
                $this->priceCalculator->portionPrice($trip, $fromStop, $toStop, 1),
            );
        }

        return $matches;
    }

    /**
     * Loads every stop of the matching rows in one query, indexed by id.
     *
     * @param list<array{fromId: int, toId: int}> $rows
     *
     * @return array<int, TripStop>
     */
    private function loadStops(array $rows): array
    {
        $ids = array_unique(array_merge(array_column($rows, 'fromId'), array_column($rows, 'toId')));

        $stops = [];
        /** @var TripStop $stop */
        foreach ($this->em->createQuery('SELECT s, trip FROM '.TripStop::class.' s JOIN s.trip trip WHERE s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getResult() as $stop) {
            $stops[$stop->getId()] = $stop;
        }

        return $stops;
    }

    /**
     * Loads the bookings of all the trips in one query, grouped by trip id.
     *
     * @param list<Trip> $trips
     *
     * @return array<int, list<Booking>>
     */
    private function loadBookings(array $trips): array
    {
        /** @var list<Booking> $bookings */
        $bookings = $this->em->createQuery(
            'SELECT b, f, t FROM '.Booking::class.' b JOIN b.fromStop f JOIN b.toStop t WHERE b.trip IN (:trips)'
        )
            ->setParameter('trips', $trips)
            ->getResult();

        $byTrip = [];
        foreach ($bookings as $booking) {
            $byTrip[$booking->getTrip()->getId()][] = $booking;
        }

        return $byTrip;
    }
}
