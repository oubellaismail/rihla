<?php

namespace App\Tests\Integration\Service;

use App\Enum\BookingStatus;
use App\Exception\ReviewNotAllowedException;
use App\Service\ReviewService;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** Reviews go through ReviewPolicy; the service saves them and enforces "once per author". */
final class ReviewServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private DbTrips $db;
    private ReviewService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-13 10:00'); // the day after the trip
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->service = static::getContainer()->get(ReviewService::class);
    }

    public function testPassengerReviewsTheDriver(): void
    {
        $karim = $this->db->user('Karim');
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(3, $karim), 1, 2, 1, BookingStatus::Completed, $salma);

        $review = $this->service->review($booking, $salma, 5, 'Ponctuel et prudent.');

        self::assertNotNull($review->getId());
        self::assertSame($karim, $review->getTarget());
        self::assertSame($salma, $review->getAuthor());
        self::assertSame(5, $review->getRating());
        self::assertSame('Ponctuel et prudent.', $review->getComment());
    }

    public function testDriverReviewsThePassenger(): void
    {
        $karim = $this->db->user('Karim');
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(3, $karim), 1, 2, 1, BookingStatus::Completed, $salma);

        $review = $this->service->review($booking, $karim, 4, null);

        self::assertSame($salma, $review->getTarget());
    }

    public function testBothSidesCanReviewTheSameBooking(): void
    {
        $karim = $this->db->user('Karim');
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(3, $karim), 1, 2, 1, BookingStatus::Completed, $salma);

        $this->service->review($booking, $salma, 5, null);
        $this->service->review($booking, $karim, 5, null);

        self::addToAssertionCount(1);
    }

    public function testTheSameAuthorCannotReviewTwice(): void
    {
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(), 1, 2, 1, BookingStatus::Completed, $salma);
        $this->service->review($booking, $salma, 5, null);

        $this->expectException(ReviewNotAllowedException::class);
        $this->service->review($booking, $salma, 3, 'Je change d\'avis.');
    }

    public function testNoReviewBeforeTheTripIsCompleted(): void
    {
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(), 1, 2, 1, BookingStatus::Confirmed, $salma);

        $this->expectException(ReviewNotAllowedException::class);
        $this->service->review($booking, $salma, 5, null);
    }

    public function testRatingMustBeBetweenOneAndFive(): void
    {
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(), 1, 2, 1, BookingStatus::Completed, $salma);

        $this->expectException(ReviewNotAllowedException::class);
        $this->service->review($booking, $salma, 6, null);
    }

    public function testNoReviewAfterTheFourteenDayWindow(): void
    {
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(), 1, 2, 1, BookingStatus::Completed, $salma);
        static::mockTime('2026-11-27 10:00');

        $this->expectException(ReviewNotAllowedException::class);
        $this->service->review($booking, $salma, 5, null);
    }
}
