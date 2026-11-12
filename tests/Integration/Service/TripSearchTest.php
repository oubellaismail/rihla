<?php

namespace App\Tests\Integration\Service;

use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Model\TripMatch;
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

    public function testAFullPortionIsListedAsFullAndTheSeatsFreedLaterAreStillOffered(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 2, 3); // Agadir → Marrakech, the car is full until Marrakech

        $full = $this->search('Agadir', 'Essaouira');
        self::assertCount(1, $full, 'a driver does offer it: say it is full, do not pretend nobody does');
        self::assertTrue($full[0]->full);
        self::assertSame(0, $full[0]->freeSeats);
        self::assertTrue($this->search('Essaouira', 'Casablanca')[0]->full, 'Essaouira → Marrakech is full');

        $results = $this->search('Marrakech', 'Casablanca');
        self::assertCount(1, $results);
        self::assertFalse($results[0]->full);
        self::assertSame(3, $results[0]->freeSeats);
    }

    public function testTheRequestedNumberOfSeatsMustBeFreeOnEveryLegOfThePortion(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 1, 2, 2); // 1 seat left between Essaouira and Marrakech

        $two = $this->search('Agadir', 'Casablanca', seats: 2);
        self::assertCount(1, $two);
        self::assertTrue($two[0]->full, 'full for 2 passengers');

        $results = $this->search('Agadir', 'Casablanca', seats: 1);
        self::assertCount(1, $results);
        self::assertFalse($results[0]->full);
        self::assertSame(1, $results[0]->freeSeats);
    }

    public function testTripsThatFitComeBeforeFullOnes(): void
    {
        $fullEarly = $this->db->trip(stops: [['Agadir', '06:30', 70], ['Essaouira', '08:15', null]]);
        $this->db->booking($fullEarly, 0, 1, 3);
        $late = $this->db->trip();

        $results = $this->search('Agadir', 'Essaouira');

        self::assertSame([$late, $fullEarly], array_map(static fn (TripMatch $m) => $m->trip, $results));
    }

    public function testAMatchKnowsThePriceOfTheWholeTrip(): void
    {
        $this->db->trip();

        $match = $this->search('Essaouira', 'Marrakech')[0];

        self::assertSame(65, $match->pricePerSeat);
        self::assertSame(210, $match->wholeTripPrice, 'to show « 65 MAD au lieu de 210 MAD »');
    }

    public function testWithoutADateTheNextDeparturesOnThatRouteAreListed(): void
    {
        $this->db->trip(day: '2026-11-11');
        $this->db->trip(day: '2026-11-13');
        $this->db->trip(day: '2026-11-09'); // already gone

        $results = static::getContainer()->get(TripSearch::class)
            ->search($this->db->city('Essaouira'), $this->db->city('Marrakech'), null);

        self::assertSame(
            ['2026-11-11 09:45', '2026-11-13 09:45'],
            array_map(static fn (TripMatch $m) => $m->from->getDepartureAt()->format('Y-m-d H:i'), $results),
        );
    }

    public function testUpcomingListsTheNextTripsFromTheirFirstStop(): void
    {
        $later = $this->db->trip(day: '2026-11-12');
        $sooner = $this->db->trip(stops: [['Marrakech', '18:00', 90], ['Casablanca', '21:00', null]], day: '2026-11-10');
        $this->db->trip(stops: [['Agadir', '08:00', 60], ['Essaouira', '09:45', null]], day: '2026-11-10'); // left at 08:00
        $this->db->trip(day: '2026-11-14')->setStatus(TripStatus::Cancelled);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $upcoming = static::getContainer()->get(TripSearch::class)->upcoming(10);

        self::assertSame([$sooner, $later], array_map(static fn (TripMatch $m) => $m->trip, $upcoming));
        self::assertSame(DbTrips::stop($later, 0), $upcoming[1]->from);
        self::assertSame(DbTrips::stop($later, 3), $upcoming[1]->to);
        self::assertSame(210, $upcoming[1]->pricePerSeat);
        self::assertSame(60, $upcoming[1]->cheapestLegPrice, 'shown as « dès 60 MAD »');
    }

    public function testUpcomingIsFullOnlyWhenNoLegHasASeat(): void
    {
        $partly = $this->db->trip();
        $this->db->booking($partly, 0, 1, 3); // only Agadir → Essaouira is full
        $full = $this->db->trip(day: '2026-11-13');
        $this->db->booking($full, 0, 3, 3);

        [$first, $second] = static::getContainer()->get(TripSearch::class)->upcoming(10);

        self::assertSame($partly, $first->trip);
        self::assertSame(0, $first->freeSeats, 'no seat for the whole trip');
        self::assertFalse($first->full, 'but Essaouira → Casablanca still has seats');
        self::assertTrue($first->partlyFree);
        self::assertTrue($second->full);
        self::assertFalse($second->partlyFree);
    }

    public function testUpcomingStopsAtTheLimit(): void
    {
        foreach (['2026-11-11', '2026-11-12', '2026-11-13'] as $day) {
            $this->db->trip(day: $day);
        }

        self::assertCount(2, static::getContainer()->get(TripSearch::class)->upcoming(2));
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
