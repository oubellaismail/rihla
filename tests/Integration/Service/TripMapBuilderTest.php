<?php

namespace App\Tests\Integration\Service;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Service\Geo\TripMapBuilder;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The data given to the map of a trip page, decided on the server for the person looking.
 *
 * Privacy rule: the exact meeting point (coordinates) is only for the driver and for passengers
 * with a confirmed (or completed) booking — and a passenger only gets the stops where they board
 * and get off. Everyone else gets the cities only: the coordinates are not in the data at all.
 *
 * Shape (JSON-ready array):
 *   viewer: 'visitor' | 'passenger' | 'driver'
 *   highlight: {from: int, to: int} | null       stop positions of the portion to draw in orange
 *   seatsOffered: int
 *   stops: list of {position, city, lat, lng, time "H:i", meetingPoint, exact: {lat, lng} | null}
 *   legs:  list of {from, to, geometry: ?string, seatsTaken: ?int}   seatsTaken only for the driver
 */
final class TripMapBuilderTest extends KernelTestCase
{
    private const MEETING = [[30.4202, -9.5982], [31.5126, -9.77], [31.6301, -8.0089], [33.5899, -7.5911]];

    private EntityManagerInterface $em;
    private DbTrips $db;
    private User $driver;
    private Trip $trip;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->driver = $this->db->user('Karim');
        $this->trip = $this->db->trip(driver: $this->driver);
        foreach ($this->trip->getStops() as $i => $stop) {
            $stop->setMeetingLatitude(self::MEETING[$i][0])->setMeetingLongitude(self::MEETING[$i][1]);
        }
        DbTrips::stop($this->trip, 0)->setLegGeometry('leg-0-line');
        $this->em->flush();
    }

    private function build(?User $viewer, ?int $from = null, ?int $to = null): array
    {
        return static::getContainer()->get(TripMapBuilder::class)->build(
            $this->trip,
            $viewer,
            null === $from ? null : DbTrips::stop($this->trip, $from),
            null === $to ? null : DbTrips::stop($this->trip, $to),
        );
    }

    /** @return array<int, ?array{lat: float, lng: float}> position => exact */
    private static function exactPins(array $map): array
    {
        return array_column($map['stops'], 'exact', 'position');
    }

    public function testAVisitorSeesTheCitiesButNoExactMeetingPoint(): void
    {
        $map = $this->build(null);

        self::assertSame('visitor', $map['viewer']);
        self::assertSame(3, $map['seatsOffered']);
        self::assertCount(4, $map['stops']);
        self::assertSame(['position' => 0, 'city' => 'Agadir', 'lat' => 30.4278, 'lng' => -9.5981, 'time' => '08:00', 'meetingPoint' => 'Centre-ville', 'exact' => null], $map['stops'][0]);
        self::assertSame([null, null, null, null], array_values(self::exactPins($map)));

        $json = json_encode($map, JSON_THROW_ON_ERROR);
        foreach (self::MEETING as [$lat, $lng]) {
            self::assertStringNotContainsString((string) $lat, $json, 'no meeting coordinates anywhere in the data');
        }
    }

    public function testAVisitorSeesTheSearchedPortionHighlighted(): void
    {
        self::assertSame(['from' => 1, 'to' => 2], $this->build(null, 1, 2)['highlight']);
        self::assertNull($this->build(null)['highlight']);
    }

    public function testLegsCarryTheirLineButNoOccupancyForAVisitor(): void
    {
        $legs = $this->build(null)['legs'];

        self::assertCount(3, $legs);
        self::assertSame(['from' => 0, 'to' => 1, 'geometry' => 'leg-0-line', 'seatsTaken' => null], $legs[0]);
        self::assertNull($legs[1]['geometry'], 'no line saved: the map draws a straight line');
    }

    public function testAPassengerWithAConfirmedBookingSeesTheExactPointsOfTheirOwnStopsOnly(): void
    {
        $salma = $this->db->user('Salma');
        $this->db->booking($this->trip, 1, 2, 1, BookingStatus::Confirmed, $salma);

        $map = $this->build($salma, 0, 3); // whatever the URL says, their own portion is shown

        self::assertSame('passenger', $map['viewer']);
        self::assertSame(['from' => 1, 'to' => 2], $map['highlight']);
        self::assertSame([
            0 => null,
            1 => ['lat' => 31.5126, 'lng' => -9.77],
            2 => ['lat' => 31.6301, 'lng' => -8.0089],
            3 => null,
        ], self::exactPins($map));
        self::assertNull($map['legs'][0]['seatsTaken']);
    }

    public function testACompletedBookingStillShowsWhereTheyMet(): void
    {
        $salma = $this->db->user('Salma');
        $this->db->booking($this->trip, 0, 1, 1, BookingStatus::Completed, $salma);

        self::assertNotNull(self::exactPins($this->build($salma))[0]);
    }

    public function testACancelledBookingGivesNoExactPoint(): void
    {
        $salma = $this->db->user('Salma');
        $this->db->booking($this->trip, 1, 2, 1, BookingStatus::CancelledByPassenger, $salma);

        $map = $this->build($salma);

        self::assertSame('visitor', $map['viewer']);
        self::assertSame([null, null, null, null], array_values(self::exactPins($map)));
    }

    public function testAnotherPassengersBookingDoesNotOpenTheMapToMe(): void
    {
        $this->db->booking($this->trip, 1, 2, 1, BookingStatus::Confirmed, $this->db->user('Salma'));

        self::assertSame('visitor', $this->build($this->db->user('Omar'))['viewer']);
    }

    public function testTheDriverSeesEveryExactPointAndHowFullEachLegIs(): void
    {
        $this->db->booking($this->trip, 0, 2, 1);                                   // legs 0 and 1
        $this->db->booking($this->trip, 1, 2, 2);                                   // leg 1
        $this->db->booking($this->trip, 2, 3, 3, BookingStatus::CancelledByDriver); // ignored

        $map = $this->build($this->driver);

        self::assertSame('driver', $map['viewer']);
        self::assertNotContains(null, self::exactPins($map));
        self::assertSame([1, 3, 0], array_column($map['legs'], 'seatsTaken'));
    }

    public function testOnACompletedTripTheDriverStillSeesHowFullEachLegWas(): void
    {
        $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed);
        $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed);

        self::assertSame([2, 2, 0], array_column($this->build($this->driver)['legs'], 'seatsTaken'));
    }

    public function testAStopWithoutMeetingCoordinatesHasNoExactPinEvenForTheDriver(): void
    {
        DbTrips::stop($this->trip, 3)->setMeetingLatitude(null)->setMeetingLongitude(null);

        self::assertNull(self::exactPins($this->build($this->driver))[3]);
    }
}
