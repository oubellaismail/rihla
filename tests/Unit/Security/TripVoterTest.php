<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Enum\TripStatus;
use App\Security\TripVoter;
use App\Tests\Support\TripBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Only the driver manages their own trip. Users are in memory (no id), so ownership is not decided on ids. */
final class TripVoterTest extends TestCase
{
    private static function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /** @return iterable<array{string}> */
    public static function attributes(): iterable
    {
        yield [TripVoter::VIEW_PASSENGERS];
        yield [TripVoter::CANCEL];
    }

    #[DataProvider('attributes')]
    public function testTheDriverIsGranted(string $attribute): void
    {
        $karim = TripBuilder::user('Karim');
        $trip = TripBuilder::trip(driver: $karim);

        self::assertSame(VoterInterface::ACCESS_GRANTED, (new TripVoter())->vote(self::token($karim), $trip, [$attribute]));
    }

    #[DataProvider('attributes')]
    public function testAnotherUserIsDenied(string $attribute): void
    {
        $trip = TripBuilder::trip(driver: TripBuilder::user('Karim'));

        self::assertSame(VoterInterface::ACCESS_DENIED, (new TripVoter())->vote(self::token(TripBuilder::user('Salma')), $trip, [$attribute]));
    }

    #[DataProvider('attributes')]
    public function testAnAnonymousVisitorIsDenied(string $attribute): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new TripVoter())->vote(new NullToken(), TripBuilder::trip(), [$attribute]));
    }

    public function testOnlyAPublishedTripCanBeCancelled(): void
    {
        $karim = TripBuilder::user('Karim');
        $voter = new TripVoter();

        foreach ([TripStatus::Cancelled, TripStatus::Completed] as $status) {
            $trip = TripBuilder::trip(driver: $karim)->setStatus($status);
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::token($karim), $trip, [TripVoter::CANCEL]), $status->name);
            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::token($karim), $trip, [TripVoter::VIEW_PASSENGERS]), 'the driver still sees the passengers');
        }
    }

    public function testOtherAttributesAndSubjectsAreNotItsBusiness(): void
    {
        $karim = TripBuilder::user('Karim');
        $voter = new TripVoter();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::token($karim), TripBuilder::trip(driver: $karim), ['SOMETHING_ELSE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::token($karim), new \stdClass(), [TripVoter::CANCEL]));
    }
}
