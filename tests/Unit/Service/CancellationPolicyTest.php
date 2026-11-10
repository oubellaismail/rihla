<?php

namespace App\Tests\Unit\Service;

use App\Service\CancellationPolicy;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Passenger refund depends on the departure time of the passenger's own boarding stop:
 * 100 % up to 24 h before (inclusive), 50 % until departure (exclusive), 0 % after.
 */
final class CancellationPolicyTest extends TestCase
{
    private CancellationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new CancellationPolicy();
    }

    /** @return iterable<string, array{string, int}> */
    public static function moments(): iterable
    {
        // Booking boards at Essaouira, which leaves on 2026-11-12 at 09:45.
        yield 'two days before' => ['2026-11-10 09:45', 100];
        yield 'exactly 24 h before' => ['2026-11-11 09:45', 100];
        yield 'one minute under 24 h' => ['2026-11-11 09:46', 50];
        yield 'one hour before' => ['2026-11-12 08:45', 50];
        yield 'one minute before' => ['2026-11-12 09:44', 50];
        yield 'at departure' => ['2026-11-12 09:45', 0];
        yield 'after departure' => ['2026-11-12 11:00', 0];
    }

    #[DataProvider('moments')]
    public function testRefundPercentFollowsTheBoardingStopDeparture(string $now, int $expected): void
    {
        $trip = B::trip();
        $booking = B::booking($trip, 1, 3); // Essaouira → Casablanca

        self::assertSame($expected, $this->policy->passengerRefundPercent($booking, new \DateTimeImmutable($now)));
    }

    public function testTheTripsFirstStopDoesNotMatterOnlyThePassengersOwnStop(): void
    {
        $trip = B::trip();
        $booking = B::booking($trip, 2, 3); // boards at Marrakech, 12:00

        // The car already left Agadir at 08:00, but Marrakech is still 4 h away: 50 %.
        self::assertSame(50, $this->policy->passengerRefundPercent($booking, new \DateTimeImmutable('2026-11-12 08:00')));
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function amounts(): iterable
    {
        yield 'full refund' => [130, '2026-11-10 09:00', 130];
        yield 'half of an even price' => [130, '2026-11-12 09:00', 65];
        yield 'half of an odd price rounds up' => [65, '2026-11-12 09:00', 33];
        yield 'nothing after departure' => [130, '2026-11-12 10:00', 0];
    }

    #[DataProvider('amounts')]
    public function testRefundAmountIsRoundedToTheNearestDirham(int $totalPrice, string $now, int $expected): void
    {
        $trip = B::trip();
        $booking = B::booking($trip, 1, 2)->setTotalPrice($totalPrice);

        self::assertSame($expected, $this->policy->passengerRefundAmount($booking, new \DateTimeImmutable($now)));
    }
}
