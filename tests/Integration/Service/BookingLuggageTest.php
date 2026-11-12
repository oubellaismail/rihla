<?php

namespace App\Tests\Integration\Service;

use App\Entity\Booking;
use App\Exception\BookingNotAllowedException;
use App\Exception\NotEnoughSeatsException;
use App\Exception\SeatTakenException;
use App\Exception\TrunkFullException;
use App\Service\BookingService;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Booking with bags (luggage.drawio). The bags are checked inside the existing lock on the
 * trip row, after the seat rules, which stay as they are.
 * Trip: Agadir(0) → Essaouira(1) → Marrakech(2) → Casablanca(3), Dacia Logan 530 L, so 424 L
 * counted; 3 seats offered (A1, B1, B3). Bags: cabin 40 L, medium 70 L, large 100 L.
 */
final class BookingLuggageTest extends KernelTestCase
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

    public function testTheBagsAreSaved(): void
    {
        $trip = $this->db->trip();

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), bags: ['medium' => 1]);

        $this->em->clear();
        $saved = $this->em->getRepository(Booking::class)->find($booking->getId());
        self::assertSame(['cabin' => 0, 'medium' => 1, 'large' => 0], $saved->getBags());
        self::assertSame(65, $saved->getTotalPrice(), 'bags are free');
    }

    public function testWithoutBagsNothingChanges(): void
    {
        $trip = $this->db->trip();

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'));

        self::assertSame(0, $booking->getLuggageLitres());
    }

    public function testBagsThatNoLongerFitAreRefusedAndNothingIsSaved(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 2, seatCodes: ['A1', 'B1'], bags: ['large' => 3, 'cabin' => 1]); // 340 L, 84 L left
        $before = $this->bookingCount();

        try {
            $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), bags: ['large' => 1]);
            self::fail('100 L do not fit in 84 L');
        } catch (TrunkFullException $e) {
            self::assertSame(84, $e->getFreeLitres());
            self::assertSame('Plus assez de place dans le coffre : il reste 84 L sur cette portion.', $e->getMessage());
            self::assertInstanceOf(BookingNotAllowedException::class, $e, 'handled like the other booking refusals');
        }
        self::assertSame($before, $this->bookingCount());
    }

    public function testWhatStillFitsIsAccepted(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 2, seatCodes: ['A1', 'B1'], bags: ['large' => 3, 'cabin' => 1]); // 84 L left

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), bags: ['medium' => 1]);

        self::assertSame(70, $booking->getLuggageLitres());
    }

    public function testLitresUnloadedAtAStopCanBeUsedAgain(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 2, seatCodes: ['A1', 'B1'], bags: ['large' => 4]); // full to Essaouira

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 3), 1, $this->db->user('Salma'), bags: ['large' => 2]);

        self::assertSame(200, $booking->getLuggageLitres());
    }

    public function testAPassengerWithoutBagsCanStillBookWhenTheTrunkIsFull(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 2, seatCodes: ['A1', 'B1'], bags: ['large' => 4]); // 400 of 424 L

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'));

        self::assertSame(['B3'], $booking->getSeatCodes());
    }

    public function testATrunkTheDriverClosedTakesNoBag(): void
    {
        $trip = $this->db->trip();
        $trip->setTrunkLitres(0);
        $this->em->flush();

        $this->expectException(TrunkFullException::class);
        $this->expectExceptionMessage('Plus assez de place dans le coffre : il reste 0 L sur cette portion.');

        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), bags: ['cabin' => 1]);
    }

    public function testAtMostTwoCountedBagsPerPlace(): void
    {
        $trip = $this->db->trip();

        try {
            $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), bags: ['cabin' => 2, 'medium' => 1]);
            self::fail('3 bags for 1 place');
        } catch (BookingNotAllowedException $e) {
            self::assertNotInstanceOf(TrunkFullException::class, $e);
            self::assertSame('Au plus 2 bagages comptés par voyageur.', $e->getMessage());
        }

        $booking = $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 2, $this->db->user('Salma'), bags: ['cabin' => 2, 'medium' => 2]);
        self::assertSame(4, $booking->getBagCount(), '2 places, 4 bags');
    }

    public function testUnknownSizesAndNegativeCountsAreRefused(): void
    {
        $trip = $this->db->trip();

        foreach ([['huge' => 1], ['medium' => -1]] as $bags) {
            try {
                $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), bags: $bags);
                self::fail('invalid bags accepted: '.json_encode($bags));
            } catch (BookingNotAllowedException $e) {
                self::assertSame('Ces bagages ne sont pas valides.', $e->getMessage());
            }
        }
    }

    public function testTheSeatRulesStillComeFirst(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, seatCodes: ['B3'], bags: ['large' => 4]);

        $this->expectException(SeatTakenException::class);

        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), seatCodes: ['B3'], bags: ['large' => 1]);
    }

    public function testTheSeatCountStillComesBeforeTheTrunk(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 3, seatCodes: ['A1', 'B1', 'B3'], bags: ['large' => 4]);

        $this->expectException(NotEnoughSeatsException::class);

        $this->service->book($trip, DbTrips::stop($trip, 1), DbTrips::stop($trip, 2), 1, $this->db->user('Salma'), bags: ['large' => 1]);
    }
}
