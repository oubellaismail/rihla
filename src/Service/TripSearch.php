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
    /** How far ahead a search without a date looks. */
    private const HORIZON_DAYS = 30;

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
    public function search(City $from, City $to, ?\DateTimeImmutable $date, int $seats = 1): array
    {
        if ($seats < 1) {
            throw new \InvalidArgumentException('Il faut chercher au moins une place.');
        }

        $now = $this->clock->now();
        if (null === $date) {
            $dayStart = $now;
            $dayEnd = $now->modify('+'.self::HORIZON_DAYS.' days');
        } else {
            $dayStart = $date->setTime(0, 0);
            $dayEnd = $dayStart->modify('+1 day');
        }

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

        $fitting = [];
        $full = [];
        foreach ($pairs as [$fromStop, $toStop]) {
            $trip = $fromStop->getTrip();
            $freeSeats = $this->seatAvailability->freeSeats(
                $trip->getSeatsOffered(),
                $bookingsByTrip[$trip->getId()] ?? [],
                $fromStop->getPosition(),
                $toStop->getPosition(),
            );
            $isFull = $freeSeats < $seats;

            $match = new TripMatch(
                $trip,
                $fromStop,
                $toStop,
                $freeSeats,
                $this->priceCalculator->portionPrice($trip, $fromStop, $toStop, 1),
                $isFull,
                $this->priceCalculator->portionPrice($trip, $trip->getFirstStop(), $trip->getLastStop(), 1),
                $this->priceCalculator->cheapestLegPrice($trip),
                false,
            );
            if ($isFull) {
                $full[] = $match;
            } else {
                $fitting[] = $match;
            }
        }

        // Pairs are already in departure order; fitting trips first, full ones after.
        return array_merge($fitting, $full);
    }

    /**
     * The next published trips that have not left their first stop, as whole-trip matches.
     *
     * @return list<TripMatch>
     */
    public function upcoming(int $limit): array
    {
        /** @var list<Trip> $trips */
        $trips = $this->em->getRepository(Trip::class)->findUpcoming($this->clock->now(), $limit);
        if ([] === $trips) {
            return [];
        }

        $bookingsByTrip = $this->loadBookings($trips);

        $matches = [];
        foreach ($trips as $trip) {
            $first = $trip->getFirstStop();
            $last = $trip->getLastStop();
            $freeSeats = $this->seatAvailability->freeSeats(
                $trip->getSeatsOffered(),
                $bookingsByTrip[$trip->getId()] ?? [],
                $first->getPosition(),
                $last->getPosition(),
            );
            $wholePrice = $this->priceCalculator->portionPrice($trip, $first, $last, 1);
            // « Full » means no leg has a free seat; a trip full only on some legs stays bookable.
            $taken = $this->seatAvailability->takenPerLeg($trip->getStops()->count() - 1, $bookingsByTrip[$trip->getId()] ?? []);
            $someLegFree = [] !== array_filter($taken, static fn (int $n): bool => $n < $trip->getSeatsOffered());
            $matches[] = new TripMatch(
                $trip,
                $first,
                $last,
                $freeSeats,
                $wholePrice,
                !$someLegFree,
                $wholePrice,
                $this->priceCalculator->cheapestLegPrice($trip),
                $freeSeats < 1 && $someLegFree,
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
        // Fetch-joining the collection also initialises Trip::getBookings(), so later reads
        // (trunk space on the result cards) cost no extra query per trip.
        /** @var list<Trip> $loaded */
        $loaded = $this->em->createQuery(
            'SELECT trip, b, f, t FROM '.Trip::class.' trip
             LEFT JOIN trip.bookings b LEFT JOIN b.fromStop f LEFT JOIN b.toStop t
             WHERE trip IN (:trips)'
        )
            ->setParameter('trips', $trips)
            ->getResult();

        $byTrip = [];
        foreach ($loaded as $trip) {
            foreach ($trip->getBookings() as $booking) {
                $byTrip[$trip->getId()][] = $booking;
            }
        }

        return $byTrip;
    }
}
