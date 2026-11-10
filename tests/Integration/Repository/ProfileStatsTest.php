<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Review;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Model\RatingSummary;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Figures shown on a public profile: average rating, number of reviews, trips cancelled by the driver. */
final class ProfileStatsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DbTrips $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
    }

    private function summary(User $user): RatingSummary
    {
        return static::getContainer()->get(ReviewRepository::class)->summaryFor($user);
    }

    private function review(User $author, User $target, int $rating): void
    {
        $trip = $this->db->trip(driver: $target);
        $booking = $this->db->booking($trip, 0, 1, 1, BookingStatus::Completed, $author);
        $this->em->persist((new Review())->setBooking($booking)->setAuthor($author)->setTarget($target)->setRating($rating));
        $this->em->flush();
    }

    public function testSomeoneWithoutReviewsHasNoAverage(): void
    {
        $summary = $this->summary($this->db->user('Karim'));

        self::assertSame(0, $summary->count);
        self::assertNull($summary->average);
    }

    public function testTheAverageIsRoundedToOneDecimal(): void
    {
        $karim = $this->db->user('Karim');
        $this->review($this->db->user('Salma'), $karim, 5);
        $this->review($this->db->user('Omar'), $karim, 4);
        $this->review($this->db->user('Lina'), $karim, 4);

        $summary = $this->summary($karim);

        self::assertSame(3, $summary->count);
        self::assertSame(4.3, $summary->average);
    }

    public function testOnlyReviewsAboutThePersonCount(): void
    {
        $karim = $this->db->user('Karim');
        $salma = $this->db->user('Salma');
        $this->review($salma, $karim, 2);   // written by Salma, about Karim
        $this->review($this->db->user('Omar'), $salma, 5);

        $summary = $this->summary($salma);

        self::assertSame(1, $summary->count);
        self::assertSame(5.0, $summary->average);
    }

    public function testCountsTheTripsTheDriverCancelled(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->trip(driver: $karim)->setStatus(TripStatus::Cancelled);
        $this->db->trip(driver: $karim)->setStatus(TripStatus::Cancelled);
        $this->db->trip(driver: $karim);
        $this->db->trip()->setStatus(TripStatus::Cancelled); // another driver
        $this->em->flush();

        self::assertSame(2, static::getContainer()->get(TripRepository::class)->countCancelledBy($karim));
    }
}
