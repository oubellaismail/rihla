<?php

namespace App\Tests\Unit\Service\Seat;

use App\Enum\BookingStatus;
use App\Enum\SeatState;
use App\Service\Seat\SeatLayout;
use App\Service\Seat\SeatMap;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\TestCase;

/**
 * Seat states for a passenger's portion of the trip.
 * Trip: Agadir(0) → Essaouira(1) → Marrakech(2) → Casablanca(3), Dacia Logan (berline),
 * 3 seats offered, so A1, B1, B3 are open and B2 is « Non proposée ».
 * A booking from stop a to stop b holds its seats on legs a .. b-1.
 */
final class SeatMapTest extends TestCase
{
    private SeatMap $map;

    protected function setUp(): void
    {
        $this->map = new SeatMap(new SeatLayout());
    }

    public function testAnEmptyTripHasItsOfferedSeatsFreeAndTheOthersClosed(): void
    {
        $states = $this->map->states(B::trip(), 1, 2);

        self::assertSame(['A1', 'B1', 'B2', 'B3'], array_keys($states), 'plan order');
        self::assertSame(SeatState::Free, $states['A1']);
        self::assertSame(SeatState::Free, $states['B1']);
        self::assertSame(SeatState::NotOffered, $states['B2']);
        self::assertSame(SeatState::Free, $states['B3']);
    }

    public function testASeatHeldOnALegOfThePortionIsTaken(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, seatCodes: ['B1']); // whole trip

        self::assertSame(SeatState::Taken, $this->map->states($trip, 1, 2)['B1']);
    }

    public function testASeatHeldOnlyOutsideThePortionIsFreedOnTheWay(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 1, seatCodes: ['B3']); // Mehdi gets off at Essaouira

        $states = $this->map->states($trip, 1, 3);

        self::assertSame(SeatState::Freed, $states['B3'], 'free for Salma from Essaouira, taken before');
        self::assertSame(SeatState::Free, $states['A1']);
        self::assertSame(SeatState::Taken, $this->map->states($trip, 0, 3)['B3'], 'Rachid, from Agadir, cannot have it');
    }

    public function testPortionsThatOnlyTouchAtAStopDoNotOverlap(): void
    {
        $trip = B::trip();
        B::booking($trip, 2, 3, seatCodes: ['A1']); // Marrakech → Casablanca

        self::assertSame(SeatState::Freed, $this->map->states($trip, 0, 2)['A1']);
    }

    public function testCancelledBookingsGiveTheirSeatsBack(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, 1, BookingStatus::CancelledByPassenger, seatCodes: ['A1']);
        B::booking($trip, 0, 3, 1, BookingStatus::CancelledByDriver, seatCodes: ['B1']);

        $states = $this->map->states($trip, 1, 2);

        self::assertSame(SeatState::Free, $states['A1']);
        self::assertSame(SeatState::Free, $states['B1']);
    }

    public function testABookingOfTwoPlacesHoldsBothSeats(): void
    {
        $trip = B::trip();
        B::booking($trip, 1, 2, 2, seatCodes: ['B1', 'B3']);

        $states = $this->map->states($trip, 1, 2);

        self::assertSame(SeatState::Taken, $states['B1']);
        self::assertSame(SeatState::Taken, $states['B3']);
        self::assertSame(SeatState::Free, $states['A1']);
    }

    public function testAMonospaceHasAllSixSeats(): void
    {
        $states = $this->map->states(B::trip(seatsOffered: 6, passengerSeats: 6), 0, 3);

        self::assertSame(['A1', 'B1', 'B2', 'B3', 'C1', 'C2'], array_keys($states));
        self::assertNotContains(SeatState::NotOffered, $states);
    }

    // ---- bookings made before seat choice existed --------------------------------------------

    public function testABookingWithoutSeatsGetsTheFirstSeatsInComfortOrder(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, 2); // no seat stored

        $states = $this->map->states($trip, 1, 2);

        self::assertSame(SeatState::Taken, $states['A1']);
        self::assertSame(SeatState::Taken, $states['B1']);
        self::assertSame(SeatState::Free, $states['B3']);
    }

    public function testABookingWithoutSeatsSkipsSeatsAlreadyChosenOnItsLegs(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, seatCodes: ['A1']);
        $old = B::booking($trip, 1, 2);

        self::assertSame(['B1'], $this->map->seatsOf($trip, $old));
        self::assertSame(SeatState::Taken, $this->map->states($trip, 1, 2)['B1']);
    }

    public function testSeatsOfABookingWithSeatsAreTheStoredOnes(): void
    {
        $trip = B::trip();
        $booking = B::booking($trip, 1, 2, 2, seatCodes: ['B3', 'B1']);

        self::assertSame(['B3', 'B1'], $this->map->seatsOf($trip, $booking));
    }

    // ---- « Pas de préférence, choisir pour moi » ---------------------------------------------

    public function testAutoPickTakesTheFirstOpenSeatInComfortOrder(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, seatCodes: ['A1']);

        self::assertSame(['B1'], $this->map->autoPick($trip, 1, 2, 1));
    }

    public function testAutoPickCountsASeatFreedOnTheWayAsOpen(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, seatCodes: ['A1']);
        B::booking($trip, 0, 3, seatCodes: ['B1']);
        B::booking($trip, 0, 1, seatCodes: ['B3']);

        self::assertSame(['B3'], $this->map->autoPick($trip, 1, 3, 1));
    }

    public function testAutoPickKeepsTwoTravellersInTheSameRow(): void
    {
        $trip = B::trip();

        self::assertSame(['B1', 'B3'], $this->map->autoPick($trip, 1, 2, 2), 'both in the back, not A1 + B1');
    }

    public function testAutoPickInAMonospacePrefersRowTwoThenRowThree(): void
    {
        $trip = B::trip(seatsOffered: 6, passengerSeats: 6);
        B::booking($trip, 0, 3, seatCodes: ['B1']);
        B::booking($trip, 0, 3, seatCodes: ['B3']);

        self::assertSame(['C1', 'C2'], $this->map->autoPick($trip, 1, 2, 2), 'row 2 has a single seat left');
        self::assertSame(['A1'], $this->map->autoPick($trip, 1, 2, 1));
    }

    public function testAutoPickMixesRowsWhenNoRowHasEnough(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, seatCodes: ['B1']);

        self::assertSame(['A1', 'B3'], $this->map->autoPick($trip, 1, 2, 2));
    }

    public function testAutoPickReturnsFewerSeatsWhenTheCarIsNearlyFull(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, 2, seatCodes: ['A1', 'B1']);

        self::assertSame(['B3'], $this->map->autoPick($trip, 1, 2, 2));
    }
}
