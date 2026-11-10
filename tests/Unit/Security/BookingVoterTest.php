<?php

namespace App\Tests\Unit\Security;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Security\BookingVoter;
use App\Tests\Support\TripBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * VIEW: the passenger and the trip's driver. CANCEL: the passenger, while the booking is confirmed.
 * REVIEW: the passenger or the driver, once the booking is completed (timing is ReviewPolicy's job).
 */
final class BookingVoterTest extends TestCase
{
    private User $karim;
    private User $salma;
    private User $stranger;

    protected function setUp(): void
    {
        $this->karim = TripBuilder::user('Karim');
        $this->salma = TripBuilder::user('Salma');
        $this->stranger = TripBuilder::user('Omar');
    }

    private function booking(BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        return TripBuilder::booking(TripBuilder::trip(driver: $this->karim), 1, 2, 1, $status, $this->salma);
    }

    private function vote(TokenInterface|User $who, Booking $booking, string $attribute): int
    {
        $token = $who instanceof User ? new UsernamePasswordToken($who, 'main', $who->getRoles()) : $who;

        return (new BookingVoter())->vote($token, $booking, [$attribute]);
    }

    public function testThePassengerAndTheDriverCanViewTheBooking(): void
    {
        $booking = $this->booking();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->salma, $booking, BookingVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->karim, $booking, BookingVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->stranger, $booking, BookingVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new NullToken(), $booking, BookingVoter::VIEW));
    }

    public function testOnlyThePassengerCancelsAConfirmedBooking(): void
    {
        $booking = $this->booking();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->salma, $booking, BookingVoter::CANCEL));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->karim, $booking, BookingVoter::CANCEL), 'the driver cancels the whole trip instead');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->stranger, $booking, BookingVoter::CANCEL));
    }

    public function testABookingThatIsNoLongerConfirmedCannotBeCancelled(): void
    {
        foreach ([BookingStatus::CancelledByPassenger, BookingStatus::CancelledByDriver, BookingStatus::Completed] as $status) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->salma, $this->booking($status), BookingVoter::CANCEL), $status->name);
        }
    }

    public function testBothSidesCanReviewACompletedBooking(): void
    {
        $booking = $this->booking(BookingStatus::Completed);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->salma, $booking, BookingVoter::REVIEW));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->karim, $booking, BookingVoter::REVIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->stranger, $booking, BookingVoter::REVIEW));
    }

    public function testNoReviewBeforeTheBookingIsCompleted(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->salma, $this->booking(BookingStatus::Confirmed), BookingVoter::REVIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->salma, $this->booking(BookingStatus::CancelledByDriver), BookingVoter::REVIEW));
    }

    public function testOtherAttributesAreNotItsBusiness(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->salma, $this->booking(), 'SOMETHING_ELSE'));
    }
}
