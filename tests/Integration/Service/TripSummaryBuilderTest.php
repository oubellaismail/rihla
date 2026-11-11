<?php

namespace App\Tests\Integration\Service;

use App\Entity\Booking;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Model\PassengerRow;
use App\Model\TripSummary;
use App\Repository\ReviewRepository;
use App\Service\TripSummaryBuilder;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the driver sees once a trip is over: how many passengers travelled, what they paid,
 * how the trip was rated, and each passenger with the reviews exchanged.
 *
 * TripSummary (readonly): passengersCarried (seats of COMPLETED bookings), amountReceived
 * (total price of COMPLETED bookings), rating (RatingSummary of the reviews about the driver
 * written on THIS trip), passengers (list<PassengerRow>, COMPLETED bookings in boarding order).
 * PassengerRow (readonly): booking, reviewOfDriver (written by the passenger), reviewOfPassenger
 * (written by the driver), each ?Review.
 */
final class TripSummaryBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DbTrips $db;
    private User $driver;
    private Trip $trip;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
        $this->driver = $this->db->user('Karim');
        $this->trip = $this->db->trip(driver: $this->driver);
        $this->trip->setStatus(TripStatus::Completed);
        $this->em->flush();
    }

    private function summary(): TripSummary
    {
        return static::getContainer()->get(TripSummaryBuilder::class)->build($this->trip);
    }

    private function review(Booking $booking, User $author, User $target, int $rating, ?string $comment = null): Review
    {
        $review = (new Review())->setBooking($booking)->setAuthor($author)->setTarget($target)->setRating($rating)->setComment($comment);
        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    public function testCountsOnlyThePassengersWhoTravelledAndWhatTheyPaid(): void
    {
        $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed);          // 100 MAD
        $this->db->booking($this->trip, 1, 3, 2, BookingStatus::Completed);          // 200 MAD
        $this->db->booking($this->trip, 0, 1, 1, BookingStatus::CancelledByPassenger);
        $this->db->booking($this->trip, 2, 3, 1, BookingStatus::CancelledByDriver);

        $summary = $this->summary();

        self::assertSame(3, $summary->passengersCarried, 'seats, not bookings');
        self::assertSame(300, $summary->amountReceived);
        self::assertCount(2, $summary->passengers);
    }

    public function testPassengersAreListedInBoardingOrderWithTheReviewsExchanged(): void
    {
        $mehdi = $this->db->user('Mehdi');
        $salma = $this->db->user('Salma');
        $late = $this->db->booking($this->trip, 1, 3, 1, BookingStatus::Completed, $salma);
        $early = $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed, $mehdi);
        $mehdisReview = $this->review($early, $mehdi, $this->driver, 5, 'Ponctuel et prudent.');
        $karimsReview = $this->review($late, $this->driver, $salma, 4, 'Passagère agréable.');

        $rows = $this->summary()->passengers;

        self::assertContainsOnlyInstancesOf(PassengerRow::class, $rows);
        self::assertSame([$early, $late], array_map(static fn (PassengerRow $r) => $r->booking, $rows), 'Agadir before Essaouira');
        self::assertSame($mehdisReview, $rows[0]->reviewOfDriver);
        self::assertNull($rows[0]->reviewOfPassenger, 'Karim has not reviewed Mehdi yet');
        self::assertNull($rows[1]->reviewOfDriver);
        self::assertSame($karimsReview, $rows[1]->reviewOfPassenger);
    }

    public function testTheRatingOnlyCountsReviewsAboutTheDriverOnThisTrip(): void
    {
        $mehdi = $this->db->user('Mehdi');
        $salma = $this->db->user('Salma');
        $b1 = $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed, $mehdi);
        $b2 = $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed, $salma);
        $this->review($b1, $mehdi, $this->driver, 5);
        $this->review($b2, $salma, $this->driver, 4);
        $this->review($b1, $this->driver, $mehdi, 1); // about a passenger: not the trip's rating

        $otherTrip = $this->db->trip(driver: $this->driver);
        $other = $this->db->booking($otherTrip, 0, 1, 1, BookingStatus::Completed, $mehdi);
        $this->review($other, $mehdi, $this->driver, 1); // another trip of the same driver

        $rating = $this->summary()->rating;

        self::assertSame(4.5, $rating->average);
        self::assertSame(2, $rating->count);
    }

    public function testATripWithoutReviewsHasNoRatingYet(): void
    {
        $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed);

        $rating = $this->summary()->rating;

        self::assertNull($rating->average);
        self::assertSame(0, $rating->count);
    }

    public function testTheRepositoryFindsTheReviewsOfATripInBothDirections(): void
    {
        $mehdi = $this->db->user('Mehdi');
        $booking = $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed, $mehdi);
        $a = $this->review($booking, $mehdi, $this->driver, 5);
        $b = $this->review($booking, $this->driver, $mehdi, 4);
        $otherTrip = $this->db->trip(driver: $this->driver);
        $this->review($this->db->booking($otherTrip, 0, 1, 1, BookingStatus::Completed, $mehdi), $mehdi, $this->driver, 3);

        $found = static::getContainer()->get(ReviewRepository::class)->forTrip($this->trip);

        self::assertEqualsCanonicalizing([$a->getId(), $b->getId()], array_map(static fn (Review $r) => $r->getId(), $found));
    }
}
