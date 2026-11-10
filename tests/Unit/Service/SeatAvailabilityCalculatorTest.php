<?php

namespace App\Tests\Unit\Service;

use App\Enum\BookingStatus;
use App\Service\SeatAvailabilityCalculator;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trip used everywhere: Agadir(0) → Essaouira(1) → Marrakech(2) → Casablanca(3), 3 seats.
 * Leg i goes from stop i to stop i+1, so there are 3 legs (0, 1, 2).
 * A booking from stop a to stop b occupies legs a .. b-1.
 */
final class SeatAvailabilityCalculatorTest extends TestCase
{
    private SeatAvailabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SeatAvailabilityCalculator();
    }

    public function testEmptyTripHasNothingTakenOnAnyLeg(): void
    {
        self::assertSame([0, 0, 0], $this->calculator->takenPerLeg(3, []));
    }

    public function testABookingOccupiesOnlyTheLegsBetweenItsStops(): void
    {
        $trip = B::trip();
        $bookings = [B::booking($trip, 1, 2)]; // Essaouira → Marrakech

        self::assertSame([0, 1, 0], $this->calculator->takenPerLeg(3, $bookings));
    }

    public function testSeatsAreSummedPerLeg(): void
    {
        $trip = B::trip();
        $bookings = [
            B::booking($trip, 0, 3, 1), // whole trip
            B::booking($trip, 0, 1, 2), // Agadir → Essaouira, 2 seats
            B::booking($trip, 2, 3, 1), // Marrakech → Casablanca
        ];

        self::assertSame([3, 1, 2], $this->calculator->takenPerLeg(3, $bookings));
    }

    public function testOnlyConfirmedBookingsHoldSeats(): void
    {
        $trip = B::trip();
        $bookings = [
            B::booking($trip, 0, 3, 1, BookingStatus::Confirmed),
            B::booking($trip, 0, 3, 1, BookingStatus::CancelledByPassenger),
            B::booking($trip, 0, 3, 1, BookingStatus::CancelledByDriver),
            B::booking($trip, 0, 3, 1, BookingStatus::Completed),
        ];

        self::assertSame([1, 1, 1], $this->calculator->takenPerLeg(3, $bookings));
    }

    public function testFreeSeatsOnAnEmptyTripEqualsSeatsOffered(): void
    {
        self::assertSame(3, $this->calculator->freeSeats(3, [], 0, 3));
    }

    public function testTheBusiestLegOfThePortionDecides(): void
    {
        $trip = B::trip();
        $bookings = [
            B::booking($trip, 0, 2, 1), // legs 0, 1
            B::booking($trip, 1, 2, 1), // leg 1
        ];

        // Agadir → Casablanca crosses leg 1, which has 2 seats taken.
        self::assertSame(1, $this->calculator->freeSeats(3, $bookings, 0, 3));
        // Marrakech → Casablanca only uses leg 2, which is empty.
        self::assertSame(3, $this->calculator->freeSeats(3, $bookings, 2, 3));
    }

    public function testASeatFreedAtAnIntermediateStopIsAvailableForTheRestOfTheTrip(): void
    {
        $trip = B::trip(1); // a single seat
        $bookings = [B::booking($trip, 0, 1)]; // Youssef: Agadir → Essaouira

        self::assertSame(0, $this->calculator->freeSeats(1, $bookings, 0, 1), 'Agadir → Essaouira is full');
        self::assertSame(1, $this->calculator->freeSeats(1, $bookings, 1, 3), 'Salma can take the same seat from Essaouira');
    }

    public function testBookingsThatOnlyTouchAtAStopDoNotOverlap(): void
    {
        $trip = B::trip(1);
        $bookings = [
            B::booking($trip, 0, 1), // ends at Essaouira
            B::booking($trip, 1, 2), // starts at Essaouira
        ];

        self::assertSame([1, 1, 0], $this->calculator->takenPerLeg(3, $bookings));
        self::assertSame(1, $this->calculator->freeSeats(1, $bookings, 2, 3));
    }

    public function testAFullLegInTheMiddleBlocksEveryPortionCrossingIt(): void
    {
        $trip = B::trip();
        $bookings = [B::booking($trip, 1, 2, 3)]; // Essaouira → Marrakech full

        self::assertSame(0, $this->calculator->freeSeats(3, $bookings, 0, 3));
        self::assertSame(0, $this->calculator->freeSeats(3, $bookings, 0, 2));
        self::assertSame(3, $this->calculator->freeSeats(3, $bookings, 0, 1));
        self::assertSame(3, $this->calculator->freeSeats(3, $bookings, 2, 3));
    }

    public function testFreeSeatsNeverGoBelowZero(): void
    {
        // Data that should never exist (more seats taken than offered, e.g. after the driver lowered seatsOffered).
        $trip = B::trip();
        $bookings = [B::booking($trip, 0, 3, 4)];

        self::assertSame(0, $this->calculator->freeSeats(3, $bookings, 0, 3));
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidPortions(): iterable
    {
        yield 'same stop' => [1, 1];
        yield 'reversed' => [2, 1];
        yield 'negative start' => [-1, 2];
    }

    #[DataProvider('invalidPortions')]
    public function testInvalidPortionIsRejected(int $from, int $to): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->freeSeats(3, [], $from, $to);
    }
}
