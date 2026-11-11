<?php

namespace App\Tests\Integration\Service;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Service\Geo\RouteEnricher;
use App\Service\TripPublisher;
use App\Tests\Support\DbTrips;
use App\Tests\Support\FakeGeoApis;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * When a trip is published, each leg gets its road distance, driving time and line from OSRM,
 * so trip pages never call OSRM. OSRM is faked here (FakeGeoApis): distance = straight line × 1.3.
 * An outage must never stop a driver from publishing.
 */
final class RouteEnricherTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private DbTrips $db;
    private User $driver;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        FakeGeoApis::reset();
        self::bootKernel();
        static::mockTime('2026-11-10 10:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->driver = $this->db->user('Karim');
        $this->vehicle = $this->db->vehicle($this->driver);
        $this->em->flush();
    }

    /** @param list<string> $cities */
    private function draft(array $cities): Trip
    {
        $trip = (new Trip())->setVehicle($this->vehicle)->setSeatsOffered(3);
        foreach ($cities as $i => $city) {
            $trip->addStop((new TripStop())
                ->setCity($this->db->city($city))
                ->setDepartureAt(new \DateTimeImmutable(sprintf('2026-11-12 %02d:00', 8 + 2 * $i)))
                ->setMeetingPoint('Centre-ville')
                ->setLegPriceToNext(60));
        }

        return $trip;
    }

    private function publish(Trip $trip): Trip
    {
        $trip = static::getContainer()->get(TripPublisher::class)->publish($trip, $this->driver);
        $this->em->clear();

        return $this->em->find(Trip::class, $trip->getId());
    }

    public function testPublishingSavesDistanceDurationAndLineOfEveryLeg(): void
    {
        $trip = $this->publish($this->draft(['Agadir', 'Essaouira', 'Marrakech']));

        foreach ([0, 1] as $position) {
            $stop = DbTrips::stop($trip, $position);
            self::assertGreaterThan(100, $stop->getLegDistanceKmToNext(), "leg $position distance");
            self::assertGreaterThan(60, $stop->getLegDurationMinToNext(), "leg $position duration");
            self::assertSame('fake-polyline', $stop->getLegGeometry());
        }
        $last = DbTrips::stop($trip, 2);
        self::assertNull($last->getLegDistanceKmToNext(), 'the last stop has no leg');
        self::assertNull($last->getLegDurationMinToNext());
        self::assertNull($last->getLegGeometry());
    }

    public function testTheExactMeetingPointIsUsedWhenTheDriverPlacedIt(): void
    {
        $draft = $this->draft(['Agadir', 'Essaouira']);
        $draft->getStops()[0]->setMeetingLatitude(30.4202)->setMeetingLongitude(-9.5982);

        $this->publish($draft);

        self::assertCount(1, FakeGeoApis::$calls);
        self::assertStringContainsString('/route/v1/driving/-9.5982,30.4202;-9.7595,31.5085', FakeGeoApis::$calls[0],
            'from the meeting point in Agadir to the centre of Essaouira');
    }

    public function testAnOsrmOutageStillPublishesTheTripWithoutRouteData(): void
    {
        FakeGeoApis::$down = true;

        $trip = $this->publish($this->draft(['Agadir', 'Essaouira']));

        self::assertNotNull($trip->getId());
        self::assertNull(DbTrips::stop($trip, 0)->getLegDistanceKmToNext());
        self::assertNull(DbTrips::stop($trip, 0)->getLegGeometry());
    }

    public function testALegBetweenCitiesWithoutCoordinatesIsLeftEmpty(): void
    {
        $trip = $this->publish($this->draft(['Agadir', 'Tafraout'])); // Tafraout: no coordinates in the test database

        self::assertSame([], FakeGeoApis::$calls, 'nothing to ask OSRM');
        self::assertNull(DbTrips::stop($trip, 0)->getLegDistanceKmToNext());
    }

    public function testEnrichingAgainDoesNotCallOsrmTwiceForTheSameLeg(): void
    {
        $enricher = static::getContainer()->get(RouteEnricher::class);

        $enricher->enrich($this->draft(['Agadir', 'Essaouira']));
        $enricher->enrich($this->draft(['Agadir', 'Essaouira']));

        self::assertCount(1, FakeGeoApis::$calls, 'the second time comes from the cache');
    }
}
