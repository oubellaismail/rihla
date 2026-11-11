<?php

namespace App\Service\Geo;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Service\SeatAvailabilityCalculator;

/**
 * The data given to the map of a trip page, decided on the server for the person looking.
 *
 * Privacy: the exact meeting coordinates are only included for the driver, and for a passenger
 * with a confirmed or completed booking (only the stops where they board and get off).
 * For everybody else they are not in the data at all.
 */
final class TripMapBuilder
{
    public function __construct(private readonly SeatAvailabilityCalculator $seats)
    {
    }

    /**
     * @return array{
     *     viewer: string,
     *     highlight: array{from: int, to: int}|null,
     *     seatsOffered: int,
     *     stops: list<array<string, mixed>>,
     *     legs: list<array<string, mixed>>
     * }
     */
    public function build(Trip $trip, ?User $viewer, ?TripStop $from = null, ?TripStop $to = null): array
    {
        $stops = array_values($trip->getStops()->toArray());
        $isDriver = $this->sameUser($trip->getDriver(), $viewer);
        $bookings = $isDriver || null === $viewer ? [] : $this->bookingsOf($trip, $viewer);

        $viewerType = $isDriver ? 'driver' : ([] !== $bookings ? 'passenger' : 'visitor');

        $revealed = []; // positions whose exact point may be shown
        $highlight = null !== $from && null !== $to
            ? ['from' => (int) $from->getPosition(), 'to' => (int) $to->getPosition()]
            : null;
        if ($isDriver) {
            $revealed = array_map(static fn (TripStop $s) => $s->getPosition(), $stops);
        } elseif ([] !== $bookings) {
            $highlight = null;
            foreach ($bookings as $booking) {
                $revealed[] = $booking->getFromStop()->getPosition();
                $revealed[] = $booking->getToStop()->getPosition();
                $highlight ??= [
                    'from' => (int) $booking->getFromStop()->getPosition(),
                    'to' => (int) $booking->getToStop()->getPosition(),
                ];
            }
        }

        $stopsData = [];
        foreach ($stops as $stop) {
            $stopsData[] = [
                'position' => $stop->getPosition(),
                'city' => (string) $stop->getCity()?->getName(),
                'lat' => $stop->getCity()?->getLatitude(),
                'lng' => $stop->getCity()?->getLongitude(),
                'time' => $stop->getDepartureAt()?->format('H:i'),
                'meetingPoint' => $stop->getMeetingPoint(),
                'exact' => \in_array($stop->getPosition(), $revealed, true) ? $this->exactOf($stop) : null,
            ];
        }

        // Once the trip is over (or cancelled) occupancy is meaningless: no colours.
        $showOccupancy = $isDriver && !\in_array($trip->getStatus(), [TripStatus::Completed, TripStatus::Cancelled], true);
        $taken = $showOccupancy ? $this->seats->occupiedPerLeg(max(0, \count($stops) - 1), $trip->getBookings()) : [];
        $legsData = [];
        for ($i = 0; $i < \count($stops) - 1; ++$i) {
            $legsData[] = [
                'from' => $stops[$i]->getPosition(),
                'to' => $stops[$i + 1]->getPosition(),
                'geometry' => $stops[$i]->getLegGeometry(),
                'seatsTaken' => $showOccupancy ? ($taken[$i] ?? 0) : null,
            ];
        }

        return [
            'viewer' => $viewerType,
            'highlight' => $highlight,
            'seatsOffered' => $trip->getSeatsOffered(),
            'stops' => $stopsData,
            'legs' => $legsData,
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function exactOf(TripStop $stop): ?array
    {
        if (null === $stop->getMeetingLatitude() || null === $stop->getMeetingLongitude()) {
            return null;
        }

        return ['lat' => $stop->getMeetingLatitude(), 'lng' => $stop->getMeetingLongitude()];
    }

    /** @return list<Booking> */
    private function bookingsOf(Trip $trip, User $viewer): array
    {
        $mine = [];
        foreach ($trip->getBookings() as $booking) {
            if (\in_array($booking->getStatus(), [BookingStatus::Confirmed, BookingStatus::Completed], true)
                && $this->sameUser($booking->getPassenger(), $viewer)) {
                $mine[] = $booking;
            }
        }

        return $mine;
    }

    private function sameUser(?User $a, ?User $b): bool
    {
        return null !== $a && null !== $b && ($a === $b || (null !== $a->getId() && $a->getId() === $b->getId()));
    }
}
