<?php

namespace App\Tests\Integration\Service;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Exception\TripNotPublishableException;
use App\Service\TripPublisher;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Publish errors name the stop or the leg at fault (docs/UX-REVIEW-FLOWS.md, fix 10).
 * Stops are numbered from 1 as the driver sees them on the form. "Now" is 2026-11-10 10:00.
 */
final class TripPublisherMessagesTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private DbTrips $db;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-10 10:00');
        $this->db = new DbTrips(static::getContainer()->get(EntityManagerInterface::class));
    }

    /** @param list<array{0: string, 1: string, 2: ?int}> $stops */
    private function refusal(array $stops): string
    {
        $driver = $this->db->user('Karim');
        $trip = (new Trip())->setDriver($driver)->setVehicle($this->db->vehicle($driver))->setSeatsOffered(3);
        foreach ($stops as [$city, $at, $price]) {
            $trip->addStop((new TripStop())
                ->setCity($this->db->city($city))
                ->setDepartureAt(new \DateTimeImmutable($at))
                ->setMeetingPoint('Centre-ville')
                ->setLegPriceToNext($price));
        }

        try {
            static::getContainer()->get(TripPublisher::class)->publish($trip, $driver);
        } catch (TripNotPublishableException $e) {
            return $e->getMessage();
        }
        self::fail('the trip was published');
    }

    public function testATimeThatDoesNotIncreaseNamesTheStop(): void
    {
        self::assertSame(
            'Arrêt 2 (Essaouira) : l\'heure doit être après celle de l\'arrêt précédent.',
            $this->refusal([['Agadir', '2026-11-12 08:00', 60], ['Essaouira', '2026-11-12 07:00', null]]),
        );
    }

    public function testTheSameCityTwiceNamesTheStop(): void
    {
        self::assertSame(
            'Arrêt 2 (Agadir) : même ville que l\'arrêt précédent.',
            $this->refusal([['Agadir', '2026-11-12 08:00', 60], ['Agadir', '2026-11-12 09:00', 65], ['Marrakech', '2026-11-12 12:00', null]]),
        );
    }

    public function testAMissingPriceNamesTheLeg(): void
    {
        self::assertSame(
            'Tronçon Essaouira → Marrakech : indiquez un prix (0 si gratuit).',
            $this->refusal([['Agadir', '2026-11-12 08:00', 60], ['Essaouira', '2026-11-12 09:45', null], ['Marrakech', '2026-11-12 12:00', null]]),
        );
    }

    public function testANegativePriceNamesTheLeg(): void
    {
        self::assertSame(
            'Tronçon Agadir → Essaouira : le prix ne peut pas être négatif.',
            $this->refusal([['Agadir', '2026-11-12 08:00', -10], ['Essaouira', '2026-11-12 09:45', null]]),
        );
    }
}
