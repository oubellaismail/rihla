<?php

namespace App\Tests\Integration\Service;

use App\Entity\Booking;
use App\Exception\BookingNotAllowedException;
use App\Exception\NotEnoughSeatsException;
use App\Exception\SeatTakenException;
use App\Service\BookingService;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Booking with chosen seats (seat-choice.drawio). The seats are checked inside the existing lock
 * on the trip row, after the per-leg seat count, which stays as it is.
 * Trip: Agadir(0) → Essaouira(1) → Marrakech(2) → Casablanca(3), Dacia Logan, 3 seats offered:
 * A1, B1, B3 open, B2 « Non proposée ».
 */
final class BookingSeatTest extends KernelTestCase
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

    public function testTheChosenSeatsAreSaved(): void
    {
        $trip = $this->db->trip();

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 2, $this->db->user('Salma'), seatCodes: ['B3', 'B1']);

        $this->em->clear();
        $saved = $this->em->getRepository(Booking::class)->find($booking->getId());
        self::assertSame(['B3', 'B1'], $saved->getSeatCodes());
        self::assertSame(2, $saved->getSeats());
    }

    public function testWithoutAChoiceRihlaPicksTheSeats(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, seatCodes: ['B1']);

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'));

        self::assertSame(['A1'], $booking->getSeatCodes());
    }

    public function testAutoPickedSeatsForTwoAreInTheSameRow(): void
    {
        $trip = $this->db->trip();

        $booking = $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 3), 2, $this->db->user('Salma'));

        self::assertSame(['B1', 'B3'], $booking->getSeatCodes());
    }

    public function testASeatTakenOnTheWayIsRefusedAndNothingIsSaved(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, seatCodes: ['B3']);
        $before = $this->bookingCount();

        try {
            $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), seatCodes: ['B3']);
            self::fail('B3 is already taken on that portion');
        } catch (SeatTakenException $e) {
            self::assertSame(['B3'], $e->getSeatCodes());
            self::assertSame("La place B3 vient d'être réservée.", $e->getMessage());
            self::assertInstanceOf(BookingNotAllowedException::class, $e, 'handled like the other booking refusals');
        }
        self::assertSame($before, $this->bookingCount());
    }

    public function testTheMessageNamesEverySeatThatIsGone(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 2, seatCodes: ['A1', 'B1']);

        $this->expectException(SeatTakenException::class);
        $this->expectExceptionMessage("Les places A1 et B1 viennent d'être réservées.");

        $this->service->book($trip, DbTrips::stop($trip, 0), DbTrips::stop($trip, 3), 2, $this->db->user('Salma'), seatCodes: ['A1', 'B1']);
    }

    public function testTheSameSeatCanBeSoldAgainAfterThePassengerGetsOff(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, seatCodes: ['B3']); // Agadir → Essaouira

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 3), 1, $this->db->user('Salma'), seatCodes: ['B3']);

        self::assertSame(['B3'], $booking->getSeatCodes());
    }

    public function testASeatTheDriverDoesNotOfferIsRefused(): void
    {
        $trip = $this->db->trip();

        $this->expectException(BookingNotAllowedException::class);
        $this->expectExceptionMessage("La place B2 n'est pas proposée sur ce trajet.");

        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), seatCodes: ['B2']);
    }

    public function testAnUnknownSeatIsRefused(): void
    {
        $trip = $this->db->trip();

        $this->expectException(BookingNotAllowedException::class);
        $this->expectExceptionMessage("La place C1 n'est pas proposée sur ce trajet.");

        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), seatCodes: ['C1']);
    }

    public function testOneSeatMustBeChosenPerPlace(): void
    {
        $trip = $this->db->trip();

        try {
            $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 2, $this->db->user('Salma'), seatCodes: ['B1']);
            self::fail('2 places but 1 seat');
        } catch (BookingNotAllowedException $e) {
            self::assertNotInstanceOf(SeatTakenException::class, $e);
            self::assertSame('Choisissez 2 places, une par voyageur.', $e->getMessage());
        }

        $this->expectExceptionMessage('Choisissez 2 places, une par voyageur.');
        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 2, $this->db->user('Salma'), seatCodes: ['B1', 'B1']);
    }

    public function testTheSeatCountRuleStillComesFirst(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 2, seatCodes: ['A1', 'B1']);

        $this->expectException(NotEnoughSeatsException::class);

        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 2, $this->db->user('Salma'), seatCodes: ['B3', 'B2']);
    }

    public function testASeatHeldByABookingWithoutSeatsIsTakenToo(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3); // made before seat choice: holds A1

        $this->expectException(SeatTakenException::class);

        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), seatCodes: ['A1']);
    }
}
