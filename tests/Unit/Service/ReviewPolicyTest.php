<?php

namespace App\Tests\Unit\Service;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Service\ReviewPolicy;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A review is allowed when the booking is COMPLETED, the author is its passenger or the trip's driver,
 * the author has not reviewed this booking yet, and at most 14 days have passed since the
 * passenger's drop-off time (inclusive). Passenger reviews the driver; driver reviews the passenger.
 */
final class ReviewPolicyTest extends TestCase
{
    private ReviewPolicy $policy;
    private User $driver;
    private User $passenger;

    protected function setUp(): void
    {
        $this->policy = new ReviewPolicy();
        $this->driver = B::user('Karim');
        $this->passenger = B::user('Salma');
    }

    private function completedBooking(): Booking
    {
        $trip = B::trip(3, null, $this->driver);

        // Essaouira → Marrakech: drop-off at 2026-11-12 12:00.
        return B::booking($trip, 1, 2, 1, BookingStatus::Completed, $this->passenger);
    }

    public function testPassengerCanReviewAfterTheTrip(): void
    {
        self::assertTrue($this->policy->canReview($this->completedBooking(), $this->passenger, new \DateTimeImmutable('2026-11-13 10:00'), false));
    }

    public function testDriverCanReviewThePassenger(): void
    {
        self::assertTrue($this->policy->canReview($this->completedBooking(), $this->driver, new \DateTimeImmutable('2026-11-13 10:00'), false));
    }

    public function testSomeoneOutsideTheBookingCannotReview(): void
    {
        self::assertFalse($this->policy->canReview($this->completedBooking(), B::user('Inconnu'), new \DateTimeImmutable('2026-11-13 10:00'), false));
    }

    /** @return iterable<string, array{BookingStatus}> */
    public static function notCompleted(): iterable
    {
        yield 'confirmed, trip not done yet' => [BookingStatus::Confirmed];
        yield 'cancelled by passenger' => [BookingStatus::CancelledByPassenger];
        yield 'cancelled by driver' => [BookingStatus::CancelledByDriver];
    }

    #[DataProvider('notCompleted')]
    public function testOnlyACompletedBookingCanBeReviewed(BookingStatus $status): void
    {
        $booking = $this->completedBooking()->setStatus($status);

        self::assertFalse($this->policy->canReview($booking, $this->passenger, new \DateTimeImmutable('2026-11-13 10:00'), false));
    }

    public function testOnlyOneReviewPerAuthorAndBooking(): void
    {
        self::assertFalse($this->policy->canReview($this->completedBooking(), $this->passenger, new \DateTimeImmutable('2026-11-13 10:00'), true));
    }

    public function testTheWindowClosesFourteenDaysAfterDropOff(): void
    {
        $booking = $this->completedBooking();

        self::assertTrue($this->policy->canReview($booking, $this->passenger, new \DateTimeImmutable('2026-11-26 12:00'), false), 'exactly 14 days');
        self::assertFalse($this->policy->canReview($booking, $this->passenger, new \DateTimeImmutable('2026-11-26 12:01'), false), 'one minute late');
    }

    public function testTargetIsTheOtherParty(): void
    {
        $booking = $this->completedBooking();

        self::assertSame($this->driver, $this->policy->targetFor($booking, $this->passenger));
        self::assertSame($this->passenger, $this->policy->targetFor($booking, $this->driver));
    }

    public function testNoTargetForSomeoneOutsideTheBooking(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->targetFor($this->completedBooking(), B::user('Inconnu'));
    }
}
