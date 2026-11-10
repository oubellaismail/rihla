<?php

namespace App\Tests\Integration\Service;

use App\Entity\Booking;
use App\Enum\BookingStatus;
use App\Enum\PaymentStatus;
use App\Enum\TripStatus;
use App\Exception\BookingNotAllowedException;
use App\Exception\NotEnoughSeatsException;
use App\Service\BookingService;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * BookingService::book() on the real MySQL test database (each test is rolled back).
 * "Now" is frozen on 2026-11-10 10:00, two days before the trip.
 */
final class BookingServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private DbTrips $db;
    private BookingService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-10 10:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->service = static::getContainer()->get(BookingService::class);
    }

    private function bookingCount(): int
    {
        return $this->em->getRepository(Booking::class)->count([]);
    }

    public function testBookingAPortionCreatesAConfirmedPaidBookingAtTheFrozenPrice(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 2, $salma);

        self::assertNotNull($booking->getId(), 'the booking is saved');
        self::assertSame(BookingStatus::Confirmed, $booking->getStatus());
        self::assertSame(130, $booking->getTotalPrice(), '65 MAD x 2 seats');
        self::assertSame($salma, $booking->getPassenger());
        self::assertNotNull($booking->getPayment());
        self::assertSame(130, $booking->getPayment()->getAmount());
        self::assertSame(PaymentStatus::Paid, $booking->getPayment()->getStatus());
    }

    public function testAFullLegIsRefusedAndNothingIsSaved(): void
    {
        $trip = $this->db->trip(3);
        $this->db->booking($trip, 1, 2, 3); // Essaouira → Marrakech full
        $before = $this->bookingCount();

        try {
            $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 3), 1, $this->db->user('Salma'));
            self::fail('NotEnoughSeatsException expected');
        } catch (NotEnoughSeatsException $e) {
            self::assertSame(0, $e->getFreeSeats());
            self::assertSame(1, $e->getRequestedSeats());
            self::assertNotSame('', $e->getMessage(), 'a message for the interface');
        }
        self::assertSame($before, $this->bookingCount());
    }

    public function testAskingForMoreSeatsThanFreeReportsHowManyAreLeft(): void
    {
        $trip = $this->db->trip(3);
        $this->db->booking($trip, 0, 3, 2);

        $this->expectException(NotEnoughSeatsException::class);
        try {
            $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 2, $this->db->user('Salma'));
        } catch (NotEnoughSeatsException $e) {
            self::assertSame(1, $e->getFreeSeats());
            throw $e;
        }
    }

    public function testASeatFreedAtAnIntermediateStopCanBeBookedForTheRest(): void
    {
        $trip = $this->db->trip(1);
        $this->db->booking($trip, 0, 1); // Youssef: Agadir → Essaouira

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 3), 1, $this->db->user('Salma'));

        self::assertSame(BookingStatus::Confirmed, $booking->getStatus());
        self::assertSame(150, $booking->getTotalPrice(), '65 + 85');
    }

    public function testCancelledBookingsDoNotHoldSeats(): void
    {
        $trip = $this->db->trip(1);
        $this->db->booking($trip, 0, 3, 1, BookingStatus::CancelledByPassenger);

        $booking = $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 3), 1, $this->db->user('Salma'));

        self::assertSame(BookingStatus::Confirmed, $booking->getStatus());
    }

    public function testTheDriverCannotBookTheirOwnTrip(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(3, $karim);

        $this->expectException(BookingNotAllowedException::class);
        $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 1), 1, $karim);
    }

    public function testACancelledTripCannotBeBooked(): void
    {
        $trip = $this->db->trip();
        $trip->setStatus(TripStatus::Cancelled);
        $this->em->flush();

        $this->expectException(BookingNotAllowedException::class);
        $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 1), 1, $this->db->user('Salma'));
    }

    public function testCannotBoardAtAStopTheCarHasAlreadyLeft(): void
    {
        $trip = $this->db->trip();
        static::mockTime('2026-11-12 10:00'); // the car left Essaouira at 09:45

        $this->expectException(BookingNotAllowedException::class);
        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'));
    }

    public function testCanStillBoardLaterOnTheRouteWhileTheCarIsDriving(): void
    {
        $trip = $this->db->trip();
        static::mockTime('2026-11-12 10:00'); // left Agadir and Essaouira, Marrakech is at 12:00

        $booking = $this->service->book($trip, DbTrips::stop($trip, 2), DbTrips::stop($trip, 3), 1, $this->db->user('Salma'));

        self::assertSame(85, $booking->getTotalPrice());
    }

    public function testReversedStopsAreRefused(): void
    {
        $trip = $this->db->trip();

        $this->expectException(BookingNotAllowedException::class);
        $this->service->book($trip, DbTrips::stop($trip, 2), DbTrips::stop($trip, 1), 1, $this->db->user('Salma'));
    }

    public function testSeatsMustBeBetweenOneAndFour(): void
    {
        $trip = $this->db->trip(6);
        foreach ([0, 5] as $seats) {
            try {
                $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 1), $seats, $this->db->user('Salma'));
                self::fail("$seats seats should be refused");
            } catch (BookingNotAllowedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheOnLockedHookRunsWhileTheTripIsLocked(): void
    {
        $trip = $this->db->trip();
        $called = false;

        $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 1), 1, $this->db->user('Salma'), function () use (&$called): void {
            $called = true;
            self::assertTrue($this->em->getConnection()->isTransactionActive(), 'the hook runs inside the transaction');
        });

        self::assertTrue($called);
    }
}
