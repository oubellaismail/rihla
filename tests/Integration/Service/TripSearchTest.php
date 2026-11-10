<?php

namespace App\Tests\Integration\Service;

use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Service\TripMatch;
use App\Service\TripSearch;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Searching for a portion of a trip: from one city to another, on a given day.
 * Default trip on 2026-11-12: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00,
 * leg prices 60 / 65 / 85 MAD, 3 seats. "Now" is 2026-11-10 10:00 unless a test says otherwise.
 */
final class TripSearchTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private DbTrips $db;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-10 10:00');
        $this->db = new DbTrips(static::getContainer()->get(EntityManagerInterface::class));
    }

    /** @return list<TripMatch> */
    private function search(string $from, string $to, string $day = '2026-11-12', int $seats = 1): array
    {
        return static::getContainer()->get(TripSearch::class)
            ->search($this->db->city($from), $this->db->city($to), new \DateTimeImmutable($day), $seats);
    }

    public function testFindsTheWholeTripWithItsPriceAndFreeSeats(): void
    {
        $trip = $this->db->trip();

        $results = $this->search('Agadir', 'Casablanca');

        self::assertCount(1, $results);
        self::assertSame($trip, $results[0]->trip);
        self::assertSame(DbTrips::stop($trip, 0), $results[0]->from);
        self::assertSame(DbTrips::stop($trip, 3), $results[0]->to);
        self::assertSame(3, $results[0]->freeSeats);
        self::assertSame(210, $results[0]->pricePerSeat, '60 + 65 + 85');
    }

    public function testFindsAPortionBetweenTwoIntermediateStops(): void
    {
        $trip = $this->db->trip();

        $results = $this->search('Essaouira', 'Marrakech');

        self::assertCount(1, $results);
        self::assertSame(DbTrips::stop($trip, 1), $results[0]->from);
        self::assertSame(DbTrips::stop($trip, 2), $results[0]->to);
        self::assertSame(65, $results[0]->pricePerSeat);
    }

    public function testDoesNotFindATripGoingTheOtherWay(): void
    {
        $this->db->trip();

        self::assertSame([], $this->search('Marrakech', 'Agadir'));
    }

    public function testOnlyFindsTripsLeavingThatDayFromTheBoardingCity(): void
    {
        $this->db->trip();

        self::assertSame([], $this->search('Agadir', 'Casablanca', '2026-11-13'));
        self::assertSame([], $this->search('Agadir', 'Casablanca', '2026-11-11'));
    }

    public function testIgnoresCancelledAndCompletedTrips(): void
    {
        $this->db->trip()->setStatus(TripStatus::Cancelled);
        $this->db->trip()->setStatus(TripStatus::Completed);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame([], $this->search('Agadir', 'Casablanca'));
    }

    public function testAFullPortionIsHiddenButTheSeatsFreedLaterAreStillOffered(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 2, 3); // Agadir → Marrakech, the car is full until Marrakech

        self::assertSame([], $this->search('Agadir', 'Essaouira'));
        self::assertSame([], $this->search('Essaouira', 'Casablanca'), 'Essaouira → Marrakech is full');

        $results = $this->search('Marrakech', 'Casablanca');
        self::assertCount(1, $results);
        self::assertSame(3, $results[0]->freeSeats);
    }

    public function testTheRequestedNumberOfSeatsMustBeFreeOnEveryLegOfThePortion(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 1, 2, 2); // 1 seat left between Essaouira and Marrakech

        self::assertSame([], $this->search('Agadir', 'Casablanca', seats: 2));

        $results = $this->search('Agadir', 'Casablanca', seats: 1);
        self::assertCount(1, $results);
        self::assertSame(1, $results[0]->freeSeats);
    }

    public function testCancelledBookingsDoNotHoldSeats(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 3, BookingStatus::CancelledByPassenger);

        $results = $this->search('Agadir', 'Casablanca', seats: 3);

        self::assertCount(1, $results);
        self::assertSame(3, $results[0]->freeSeats);
    }

    public function testATripThatAlreadyLeftTheBoardingCityIsNotOffered(): void
    {
        $this->db->trip();
        static::mockTime('2026-11-12 10:00'); // gone from Agadir and Essaouira, not yet from Marrakech

        self::assertSame([], $this->search('Agadir', 'Casablanca'));
        self::assertSame([], $this->search('Essaouira', 'Marrakech'));
        self::assertCount(1, $this->search('Marrakech', 'Casablanca'));
    }

    public function testResultsAreSortedByDepartureTimeFromTheBoardingCity(): void
    {
        $late = $this->db->trip();
        $early = $this->db->trip(stops: [['Agadir', '06:30', 70], ['Essaouira', '08:15', null]]);

        $results = $this->search('Agadir', 'Essaouira');

        self::assertCount(2, $results);
        self::assertSame($early, $results[0]->trip);
        self::assertSame($late, $results[1]->trip);
        self::assertSame(70, $results[0]->pricePerSeat);
    }

    public function testAskingForLessThanOneSeatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->search('Agadir', 'Casablanca', seats: 0);
    }
}
