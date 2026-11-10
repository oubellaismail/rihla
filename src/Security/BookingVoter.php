<?php

namespace App\Security;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Booking> */
final class BookingVoter extends Voter
{
    public const VIEW = 'BOOKING_VIEW';
    public const CANCEL = 'BOOKING_CANCEL';
    public const REVIEW = 'BOOKING_REVIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Booking && \in_array($attribute, [self::VIEW, self::CANCEL, self::REVIEW], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token/* , ?Vote $vote = null */): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Booking) {
            return false;
        }

        $passenger = $subject->getPassenger();
        $driver = $subject->getTrip()?->getDriver();
        $isPassenger = null !== $passenger && self::sameUser($passenger, $user);
        $isDriver = null !== $driver && self::sameUser($driver, $user);

        return match ($attribute) {
            self::VIEW => $isPassenger || $isDriver,
            self::CANCEL => $isPassenger && BookingStatus::Confirmed === $subject->getStatus(),
            self::REVIEW => ($isPassenger || $isDriver) && BookingStatus::Completed === $subject->getStatus(),
            default => false,
        };
    }

    private static function sameUser(User $a, User $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return null !== $a->getId() && null !== $b->getId() && $a->getId() === $b->getId();
    }
}
