<?php

namespace App\Security;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\TripStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Trip> */
final class TripVoter extends Voter
{
    public const VIEW_PASSENGERS = 'TRIP_VIEW_PASSENGERS';
    public const CANCEL = 'TRIP_CANCEL';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Trip && \in_array($attribute, [self::VIEW_PASSENGERS, self::CANCEL], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token/* , ?Vote $vote = null */): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Trip) {
            return false;
        }

        $driver = $subject->getDriver();
        if (null === $driver || !self::sameUser($driver, $user)) {
            return false;
        }

        return match ($attribute) {
            self::CANCEL => TripStatus::Published === $subject->getStatus(),
            default => true,
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
