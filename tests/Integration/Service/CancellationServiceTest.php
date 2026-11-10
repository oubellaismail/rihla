<?php

namespace App\Tests\Integration\Service;

use App\Enum\BookingStatus;
use App\Enum\PaymentStatus;
use App\Enum\TripStatus;
use App\Exception\CancellationNotAllowedException;
use App\Service\CancellationService;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Passenger and driver cancellations follow different rules (see DECISIONS.md).
 * Trip: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00 on 2026-11-12.
 * DbTrips::booking() sets totalPrice = 100 MAD per seat.
 */
final class CancellationServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private DbTrips $db;
    private CancellationService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-10 10:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->service = static::getContainer()->get(CancellationService::class);
    }

    public function testPassengerCancellingEarlyIsFullyRefunded(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $salma);

        $payment = $this->service->cancelByPassenger($booking, $salma);

        self::assertSame(BookingStatus::CancelledByPassenger, $booking->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-10 10:00'), $booking->getCancelledAt());
        self::assertSame(100, $payment->getRefundedAmount());
        self::assertSame(PaymentStatus::Refunded, $payment->getStatus());
    }

    public function testPassengerCancellingLessThan24HoursBeforeGetsHalf(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $salma);
        static::mockTime('2026-11-12 08:00'); // Essaouira leaves at 09:45

        $payment = $this->service->cancelByPassenger($booking, $salma);

        self::assertSame(50, $payment->getRefundedAmount());
        self::assertSame(PaymentStatus::PartiallyRefunded, $payment->getStatus());
    }

    public function testCancelledSeatIsFreeAgain(): void
    {
        $trip = $this->db->trip(1);
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 0, 3, 1, BookingStatus::Confirmed, $salma);

        $this->service->cancelByPassenger($booking, $salma);

        self::assertFalse($booking->getStatus()->holdsSeats());
    }

    public function testOnlyThePassengerCanCancelTheirBooking(): void
    {
        $trip = $this->db->trip();
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $this->db->user('Salma'));

        $this->expectException(CancellationNotAllowedException::class);
        $this->service->cancelByPassenger($booking, $this->db->user('Youssef'));
    }

    public function testABookingCannotBeCancelledTwice(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::CancelledByPassenger, $salma);

        $this->expectException(CancellationNotAllowedException::class);
        $this->service->cancelByPassenger($booking, $salma);
    }

    public function testDriverCancellingTheTripRefundsEveryConfirmedBookingInFull(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(3, $karim);
        $a = $this->db->booking($trip, 0, 1, 1);
        $b = $this->db->booking($trip, 1, 3, 2);
        $alreadyCancelled = $this->db->booking($trip, 0, 3, 1, BookingStatus::CancelledByPassenger);

        $count = $this->service->cancelTripByDriver($trip, $karim);

        self::assertSame(2, $count, 'only confirmed bookings are cancelled');
        self::assertSame(TripStatus::Cancelled, $trip->getStatus());
        foreach ([$a, $b] as $booking) {
            self::assertSame(BookingStatus::CancelledByDriver, $booking->getStatus());
            self::assertSame($booking->getTotalPrice(), $booking->getPayment()->getRefundedAmount());
            self::assertSame(PaymentStatus::Refunded, $booking->getPayment()->getStatus());
        }
        self::assertSame(BookingStatus::CancelledByPassenger, $alreadyCancelled->getStatus(), 'untouched');
    }

    public function testDriverCancellingLateStillRefundsPassengersInFull(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(3, $karim);
        $booking = $this->db->booking($trip, 0, 1);
        static::mockTime('2026-11-12 07:30'); // 30 min before departure

        $this->service->cancelTripByDriver($trip, $karim);

        self::assertSame(100, $booking->getPayment()->getRefundedAmount());
    }

    public function testOnlyTheDriverCanCancelTheTrip(): void
    {
        $trip = $this->db->trip();

        $this->expectException(CancellationNotAllowedException::class);
        $this->service->cancelTripByDriver($trip, $this->db->user('Salma'));
    }

    public function testATripThatHasStartedCannotBeCancelled(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(3, $karim);
        static::mockTime('2026-11-12 08:00'); // departure time from Agadir

        $this->expectException(CancellationNotAllowedException::class);
        $this->service->cancelTripByDriver($trip, $karim);
    }
}
