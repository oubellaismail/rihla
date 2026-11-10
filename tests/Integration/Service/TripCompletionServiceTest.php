<?php

namespace App\Tests\Integration\Service;

use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Service\TripCompletionService;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/** A published trip is completed once its last stop's time has passed (Casablanca 15:00 on 2026-11-12). */
final class TripCompletionServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private DbTrips $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
    }

    private function service(): TripCompletionService
    {
        return static::getContainer()->get(TripCompletionService::class);
    }

    public function testATripStillOnTheRoadIsNotCompleted(): void
    {
        $trip = $this->db->trip();
        static::mockTime('2026-11-12 14:59');

        $this->service()->completeFinishedTrips();

        self::assertSame(TripStatus::Published, $trip->getStatus());
    }

    public function testAFinishedTripAndItsConfirmedBookingsAreCompleted(): void
    {
        $trip = $this->db->trip();
        $confirmed = $this->db->booking($trip, 0, 2);
        $cancelled = $this->db->booking($trip, 1, 3, 1, BookingStatus::CancelledByPassenger);
        static::mockTime('2026-11-12 15:00');

        $count = $this->service()->completeFinishedTrips();

        $this->em->refresh($trip);
        $this->em->refresh($confirmed);
        $this->em->refresh($cancelled);
        self::assertGreaterThanOrEqual(1, $count);
        self::assertSame(TripStatus::Completed, $trip->getStatus());
        self::assertSame(BookingStatus::Completed, $confirmed->getStatus());
        self::assertSame(BookingStatus::CancelledByPassenger, $cancelled->getStatus(), 'cancelled bookings stay cancelled');
    }

    public function testACancelledTripIsNeverCompleted(): void
    {
        $trip = $this->db->trip();
        $trip->setStatus(TripStatus::Cancelled);
        $this->em->flush();
        static::mockTime('2026-11-13 10:00');

        $this->service()->completeFinishedTrips();

        $this->em->refresh($trip);
        self::assertSame(TripStatus::Cancelled, $trip->getStatus());
    }

    public function testRunningItTwiceChangesNothingMore(): void
    {
        $this->db->trip();
        static::mockTime('2026-11-13 10:00');

        $this->service()->completeFinishedTrips();

        self::assertSame(0, $this->service()->completeFinishedTrips());
    }

    public function testTheConsoleCommandRunsTheService(): void
    {
        $trip = $this->db->trip();
        static::mockTime('2026-11-13 10:00');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:trips:complete'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->em->refresh($trip);
        self::assertSame(TripStatus::Completed, $trip->getStatus());
    }
}
