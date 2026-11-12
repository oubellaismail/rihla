<?php

namespace App\Tests\Integration\Concurrency;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Tests\Support\DbTrips;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Two separate PHP processes try to book the last seat at the same moment.
 * The first one holds the lock on the trip row for a while; the second one must wait,
 * then see that the seat is gone. Exactly one booking may exist afterwards.
 *
 * Data is really committed here (no rollback), so the processes can see it; tearDown cleans up.
 */
#[SkipDatabaseRollback]
final class BookingConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ?Trip $trip = null;
    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTwoSimultaneousBookingsForTheLastSeatNeverBothSucceed(): void
    {
        $db = new DbTrips($this->em);
        // The child processes run on the real clock (a mocked clock does not cross processes),
        // so the trip must really be in the future, whatever day the tests run.
        $this->trip = $db->trip(1, day: (new \DateTimeImmutable('+30 days'))->format('Y-m-d'));
        $salma = $db->user('Salma');
        $youssef = $db->user('Youssef');
        $this->users = [$this->trip->getDriver(), $salma, $youssef];
        $this->em->flush();

        // Both want Essaouira (1) → Marrakech (2); the trip has one seat.
        $first = $this->bookInAnotherProcess($salma, holdLockMs: 1500);
        $first->start();
        usleep(300_000); // let the first process take the lock
        $second = $this->bookInAnotherProcess($youssef, holdLockMs: 0);
        $second->start();

        $first->wait();
        $second->wait();

        $codes = [$first->getExitCode(), $second->getExitCode()];
        sort($codes);
        self::assertSame([0, 3], $codes, sprintf(
            "Expected one success (0) and one 'not enough seats' (3).\nFirst: %s %s\nSecond: %s %s",
            $first->getOutput(), $first->getErrorOutput(), $second->getOutput(), $second->getErrorOutput(),
        ));

        $this->em->clear();
        $confirmed = $this->em->getRepository(Booking::class)->count(['trip' => $this->trip->getId(), 'status' => BookingStatus::Confirmed]);
        self::assertSame(1, $confirmed, 'never two bookings for one seat');
    }

    private function bookInAnotherProcess(User $passenger, int $holdLockMs): Process
    {
        $process = new Process(
            ['php', 'bin/console', 'app:booking:book', (string) $this->trip->getId(), '1', '2', $passenger->getEmail(), '--hold-lock-ms=' . $holdLockMs],
            static::getContainer()->getParameter('kernel.project_dir'),
            ['APP_ENV' => 'test'],
        );
        $process->setTimeout(30);

        return $process;
    }

    protected function tearDown(): void
    {
        if ($this->trip?->getId()) {
            $conn = $this->em->getConnection();
            $tripId = $this->trip->getId();
            $conn->executeStatement('DELETE p FROM payment p JOIN booking b ON b.id = p.booking_id WHERE b.trip_id = ?', [$tripId]);
            $conn->executeStatement('DELETE FROM booking WHERE trip_id = ?', [$tripId]);
            $conn->executeStatement('DELETE FROM trip_stop WHERE trip_id = ?', [$tripId]);
            $conn->executeStatement('DELETE FROM trip WHERE id = ?', [$tripId]);
            foreach ($this->users as $user) {
                $conn->executeStatement('DELETE FROM vehicle WHERE owner_id = ?', [$user->getId()]);
                $conn->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()]);
            }
        }
        parent::tearDown();
    }
}
