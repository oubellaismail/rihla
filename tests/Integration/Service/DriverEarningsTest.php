<?php

namespace App\Tests\Integration\Service;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Model\EarningsReport;
use App\Service\DriverEarnings;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * What a driver earned, will earn, and how full the car was (« Mes gains »). "Now" is 2026-11-10 10:00.
 *
 * Every test trip: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00, 3 legs, 3 seats.
 * DbTrips bookings cost 100 MAD per seat and are paid in full.
 *
 * - Earned: what the driver keeps from completed bookings and cancelled ones (paid minus refunded).
 * - Upcoming: confirmed bookings on trips that have not left.
 * - Seats sold: seats of confirmed or completed bookings.
 * - Resold seats, per trip: seats sold minus the most seats taken on any one leg (a seat freed at a stop and sold again).
 * - Fill rate: seat-legs taken over seat-legs offered, on trips that were not cancelled, as a rounded percentage.
 */
final class DriverEarningsTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private DbTrips $db;
    private EntityManagerInterface $em;
    private User $karim;
    private Trip $october;
    private Trip $november;
    private Trip $september;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-10 10:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->karim = $this->db->user('Karim');

        // October, finished: Mehdi Agadir → Essaouira, Youssef Essaouira → Casablanca on the same seat,
        // Salma cancelled late (half refunded), Nadia fully refunded.
        $this->october = $this->db->trip(driver: $this->karim, day: '2026-10-05')->setStatus(TripStatus::Completed);
        $this->db->booking($this->october, 0, 1, status: BookingStatus::Completed, passenger: $this->db->user('Mehdi'));
        $this->db->booking($this->october, 1, 3, status: BookingStatus::Completed, passenger: $this->db->user('Youssef'));
        $this->db->booking($this->october, 0, 3, 2, BookingStatus::CancelledByPassenger, $this->db->user('Salma'))->getPayment()->refund(100);
        $this->db->booking($this->october, 0, 3, status: BookingStatus::CancelledByPassenger, passenger: $this->db->user('Nadia'))->getPayment()->refund(100);

        // November, not left yet: Rachid, 2 seats Agadir → Marrakech.
        $this->november = $this->db->trip(driver: $this->karim, day: '2026-11-12');
        $this->db->booking($this->november, 0, 2, 2, passenger: $this->db->user('Rachid'));

        // September, cancelled by Karim: everything refunded.
        $this->september = $this->db->trip(driver: $this->karim, day: '2026-09-20')->setStatus(TripStatus::Cancelled);
        $this->db->booking($this->september, 0, 3, status: BookingStatus::CancelledByDriver, passenger: $this->db->user('Imane'))->getPayment()->refund(100);

        // Someone else's trip never counts.
        $other = $this->db->trip(driver: $this->db->user('Hamid'), day: '2026-10-05')->setStatus(TripStatus::Completed);
        $this->db->booking($other, 0, 3, 3, BookingStatus::Completed, $this->db->user('Omar'));

        $this->em->flush();
    }

    private function report(): EarningsReport
    {
        return static::getContainer()->get(DriverEarnings::class)->forDriver($this->karim);
    }

    public function testEarnedCountsCompletedBookingsAndWhatCancellationsLeft(): void
    {
        self::assertSame(300, $this->report()->earned, '100 + 100 + 100 kept from Salma');
    }

    public function testUpcomingCountsConfirmedBookingsOnTripsNotYetGone(): void
    {
        self::assertSame(200, $this->report()->upcoming);
    }

    public function testSeatsSoldLeaveCancelledBookingsOut(): void
    {
        $report = $this->report();

        self::assertSame(4, $report->seatsSold);
        self::assertSame(2, $report->tripsWithSales);
    }

    public function testASeatFreedAtAStopAndSoldAgainCountsAsResold(): void
    {
        self::assertSame(1, $this->report()->resoldSeats);
    }

    public function testFillRateLeavesCancelledTripsOut(): void
    {
        self::assertSame(39, $this->report()->fillRate, '(3 + 4) seat-legs over (9 + 9)');
    }

    public function testMonthsCoverTheLastSixMonthsWithEarnedAndUpcoming(): void
    {
        $months = $this->report()->months;

        self::assertSame(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_map(static fn (array $m): string => $m['month']->format('Y-m'), $months));
        self::assertSame(['earned' => 300, 'upcoming' => 0], array_intersect_key($months[4], ['earned' => 0, 'upcoming' => 0]));
        self::assertSame(['earned' => 0, 'upcoming' => 200], array_intersect_key($months[5], ['earned' => 0, 'upcoming' => 0]));
        self::assertSame(['earned' => 0, 'upcoming' => 0], array_intersect_key($months[3], ['earned' => 0, 'upcoming' => 0]), 'the cancelled trip left nothing');
    }

    public function testOneRowPerTripMostRecentFirst(): void
    {
        $rows = $this->report()->rows;

        self::assertSame([$this->november->getId(), $this->october->getId(), $this->september->getId()], array_map(static fn (array $r): ?int => $r['trip']->getId(), $rows));
        self::assertSame(['upcoming', 'completed', 'cancelled'], array_column($rows, 'state'));
        self::assertSame([200, 300, 0], array_column($rows, 'amount'));
        self::assertSame([2, 2, 0], array_column($rows, 'seats'));
        self::assertSame([[2, 2, 0], [1, 1, 1], [0, 0, 0]], array_column($rows, 'legs'), 'seats taken on each leg');
    }

    public function testADriverWithoutTripsHasAnEmptyReport(): void
    {
        $report = static::getContainer()->get(DriverEarnings::class)->forDriver($this->db->user('Leila'));

        self::assertSame(0, $report->earned);
        self::assertSame(0, $report->upcoming);
        self::assertSame(0, $report->seatsSold);
        self::assertNull($report->fillRate);
        self::assertSame([], $report->rows);
    }
}
