<?php

namespace App\Service\Seat;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Enum\SeatState;

/**
 * Which seats are free, freed on the way or taken for a portion of a trip.
 *
 * A booking from stop a to stop b holds its seats on legs a .. b-1, like in SeatAvailabilityCalculator.
 * Pass $bookings to use a fresh list (e.g. reloaded under the lock) instead of $trip->getBookings().
 */
final class SeatMap
{
    private const AUTO_PICK_ROWS = ['B', 'C', 'A'];

    public function __construct(private readonly SeatLayout $layout)
    {
    }

    /**
     * @param iterable<Booking>|null $bookings
     *
     * @return array<string, SeatState> keyed by seat code, in plan order
     */
    public function states(Trip $trip, int $fromPosition, int $toPosition, ?iterable $bookings = null): array
    {
        $held = $this->heldSeats($trip, $bookings);
        $offered = $this->layout->offeredCodes($trip);

        $states = [];
        foreach ($this->layout->seats($trip->getVehicle()->getPassengerSeats()) as $seat) {
            $code = $seat->code;
            if (!in_array($code, $offered, true)) {
                $states[$code] = SeatState::NotOffered;
                continue;
            }
            $states[$code] = SeatState::Free;
            foreach ($held as [$booking, $codes]) {
                if (!in_array($code, $codes, true)) {
                    continue;
                }
                if ($this->overlaps($booking, $fromPosition, $toPosition)) {
                    $states[$code] = SeatState::Taken;
                    break;
                }
                $states[$code] = SeatState::Freed;
            }
        }

        return $states;
    }

    /**
     * @param iterable<Booking>|null $bookings
     *
     * @return list<string>
     */
    public function seatsOf(Trip $trip, Booking $booking, ?iterable $bookings = null): array
    {
        if ([] !== $booking->getSeatCodes()) {
            return $booking->getSeatCodes();
        }
        foreach ($this->heldSeats($trip, $bookings) as [$held, $codes]) {
            if ($held === $booking) {
                return $codes;
            }
        }

        return [];
    }

    /**
     * Open seats (free or freed) in comfort order. With several travellers, the first row that has
     * room for all of them (B, then C, then A); otherwise the first open seats, possibly fewer than $count.
     *
     * @param iterable<Booking>|null $bookings
     *
     * @return list<string>
     */
    public function autoPick(Trip $trip, int $fromPosition, int $toPosition, int $count, ?iterable $bookings = null): array
    {
        $states = $this->states($trip, $fromPosition, $toPosition, $bookings);
        $open = [];
        foreach ($this->layout->comfortOrder($trip->getVehicle()->getPassengerSeats()) as $code) {
            if ($states[$code]?->isOpen()) {
                $open[] = $code;
            }
        }

        if ($count > 1) {
            foreach (self::AUTO_PICK_ROWS as $row) {
                $inRow = array_values(array_filter($open, static fn (string $code) => $code[0] === $row));
                if (count($inRow) >= $count) {
                    return array_slice($inRow, 0, $count);
                }
            }
        }

        return array_slice($open, 0, $count);
    }

    /**
     * The seats held by every booking that occupies seats, with bookings made before seat choice
     * given the first comfort seats nobody holds on their legs.
     *
     * @param iterable<Booking>|null $bookings
     *
     * @return list<array{0: Booking, 1: list<string>}>
     */
    private function heldSeats(Trip $trip, ?iterable $bookings): array
    {
        $occupying = [];
        foreach ($bookings ?? $trip->getBookings() as $booking) {
            if ($booking->getStatus()->occupiedSeat()) {
                $occupying[] = $booking;
            }
        }

        $held = [];
        $uncoded = [];
        foreach ($occupying as $index => $booking) {
            if ([] !== $booking->getSeatCodes()) {
                $held[$index] = [$booking, $booking->getSeatCodes()];
            } else {
                $uncoded[$index] = $booking;
            }
        }

        // Stable order: id, then boarding stop, then the order they came in.
        uasort($uncoded, static fn (Booking $a, Booking $b) => [$a->getId() ?? PHP_INT_MAX, $a->getFromStop()->getPosition()]
            <=> [$b->getId() ?? PHP_INT_MAX, $b->getFromStop()->getPosition()]);
        $offered = $this->layout->offeredCodes($trip);
        $comfort = array_values(array_filter(
            $this->layout->comfortOrder($trip->getVehicle()->getPassengerSeats()),
            static fn (string $code) => in_array($code, $offered, true),
        ));
        foreach ($uncoded as $index => $booking) {
            $codes = [];
            foreach ($comfort as $code) {
                if (count($codes) >= $booking->getSeats()) {
                    break;
                }
                if (!$this->heldOnItsLegs($held, $code, $booking)) {
                    $codes[] = $code;
                }
            }
            $held[$index] = [$booking, $codes];
        }
        ksort($held);

        return array_values($held);
    }

    /** @param array<int, array{0: Booking, 1: list<string>}> $held */
    private function heldOnItsLegs(array $held, string $code, Booking $booking): bool
    {
        foreach ($held as [$other, $codes]) {
            if (in_array($code, $codes, true)
                && $this->overlaps($other, $booking->getFromStop()->getPosition(), $booking->getToStop()->getPosition())) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(Booking $booking, int $fromPosition, int $toPosition): bool
    {
        return $booking->getFromStop()->getPosition() < $toPosition && $booking->getToStop()->getPosition() > $fromPosition;
    }
}
