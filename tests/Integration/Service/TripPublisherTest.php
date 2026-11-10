<?php

namespace App\Tests\Integration\Service;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\TripStatus;
use App\Exception\TripNotPublishableException;
use App\Service\TripPublisher;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A driver publishes a trip built by the "Publier un trajet" form.
 * The form gives an unsaved Trip with its stops in the order the driver typed them.
 * "Now" is 2026-11-10 10:00; the trip is on 2026-11-12.
 */
final class TripPublisherTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private DbTrips $db;
    private User $driver;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-10 10:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->driver = $this->db->user('Karim');
        $this->vehicle = $this->db->vehicle($this->driver, 4);
        $this->em->flush();
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?int}> $stops [city, Y-m-d H:i, price to next]
     */
    private function draft(array $stops, int $seats = 3, ?Vehicle $vehicle = null): Trip
    {
        $trip = (new Trip())->setVehicle($vehicle ?? $this->vehicle)->setSeatsOffered($seats);
        foreach ($stops as [$city, $at, $price]) {
            $trip->addStop((new TripStop())
                ->setCity($this->db->city($city))
                ->setDepartureAt(new \DateTimeImmutable($at))
                ->setMeetingPoint('Gare routière')
                ->setLegPriceToNext($price));
        }

        return $trip;
    }

    private function publish(Trip $trip): Trip
    {
        return static::getContainer()->get(TripPublisher::class)->publish($trip, $this->driver);
    }

    private function tripCount(): int
    {
        return $this->em->getRepository(Trip::class)->count([]);
    }

    private function assertRefused(Trip $trip, string $because): void
    {
        $before = $this->tripCount();
        try {
            $this->publish($trip);
            self::fail('The trip should have been refused: ' . $because);
        } catch (TripNotPublishableException $e) {
            self::assertNotSame('', $e->getMessage(), 'the reason is shown to the driver');
        }
        self::assertSame($before, $this->tripCount(), 'nothing is saved when the trip is refused');
    }

    public function testAValidTripIsSavedAsPublishedForTheDriver(): void
    {
        $trip = $this->publish($this->draft([
            ['Agadir', '2026-11-12 08:00', 60],
            ['Essaouira', '2026-11-12 09:45', 65],
            ['Marrakech', '2026-11-12 12:00', 85],
        ]));

        self::assertNotNull($trip->getId());
        self::assertSame($this->driver, $trip->getDriver());
        self::assertSame(TripStatus::Published, $trip->getStatus());
        self::assertSame([0, 1, 2], array_map(fn (TripStop $s) => $s->getPosition(), $trip->getStops()->toArray()));
    }

    public function testThePriceTypedOnTheLastStopIsDropped(): void
    {
        $trip = $this->publish($this->draft([
            ['Agadir', '2026-11-12 08:00', 60],
            ['Essaouira', '2026-11-12 09:45', 40],
        ]));

        self::assertNull($trip->getLastStop()->getLegPriceToNext(), 'no leg starts at the last stop');
    }

    public function testAZeroPriceLegIsAllowed(): void
    {
        $trip = $this->publish($this->draft([
            ['Agadir', '2026-11-12 08:00', 0],
            ['Essaouira', '2026-11-12 09:45', null],
        ]));

        self::assertSame(0, $trip->getFirstStop()->getLegPriceToNext());
    }

    public function testATripNeedsAtLeastTwoStops(): void
    {
        $this->assertRefused($this->draft([['Agadir', '2026-11-12 08:00', null]]), 'one stop only');
    }

    public function testStopTimesMustStrictlyIncrease(): void
    {
        $this->assertRefused($this->draft([
            ['Agadir', '2026-11-12 08:00', 60],
            ['Essaouira', '2026-11-12 08:00', 65],
            ['Marrakech', '2026-11-12 12:00', null],
        ]), 'two stops at the same time');

        $this->assertRefused($this->draft([
            ['Agadir', '2026-11-12 08:00', 60],
            ['Essaouira', '2026-11-12 07:00', null],
        ]), 'arrives before leaving');
    }

    public function testTheSameCityCannotFollowItself(): void
    {
        $this->assertRefused($this->draft([
            ['Agadir', '2026-11-12 08:00', 60],
            ['Agadir', '2026-11-12 09:00', 65],
            ['Marrakech', '2026-11-12 12:00', null],
        ]), 'Agadir twice in a row');
    }

    public function testTheFirstDepartureMustBeInTheFuture(): void
    {
        $this->assertRefused($this->draft([
            ['Agadir', '2026-11-10 09:00', 60],
            ['Essaouira', '2026-11-10 11:00', null],
        ]), 'already left');
    }

    public function testEveryLegExceptTheLastNeedsAPrice(): void
    {
        $this->assertRefused($this->draft([
            ['Agadir', '2026-11-12 08:00', 60],
            ['Essaouira', '2026-11-12 09:45', null],
            ['Marrakech', '2026-11-12 12:00', null],
        ]), 'Essaouira → Marrakech has no price');
    }

    public function testPricesCannotBeNegative(): void
    {
        $this->assertRefused($this->draft([
            ['Agadir', '2026-11-12 08:00', -10],
            ['Essaouira', '2026-11-12 09:45', null],
        ]), 'negative price');
    }

    public function testTheVehicleMustBelongToTheDriver(): void
    {
        $someoneElse = $this->db->user('Nadia');
        $hisCar = $this->db->vehicle($someoneElse);
        $this->em->flush();

        $this->assertRefused($this->draft([
            ['Agadir', '2026-11-12 08:00', 60],
            ['Essaouira', '2026-11-12 09:45', null],
        ], vehicle: $hisCar), 'not his car');
    }

    public function testSeatsOfferedMustFitInTheVehicle(): void
    {
        $stops = [['Agadir', '2026-11-12 08:00', 60], ['Essaouira', '2026-11-12 09:45', null]];

        $this->assertRefused($this->draft($stops, seats: 5), '5 seats in a 4-seat car');
        $this->assertRefused($this->draft($stops, seats: 0), 'no seat offered');
    }
}
