<?php

namespace App\Tests\Unit\Service\Geo;

use App\Service\Geo\ArrivalTimeSuggester;
use PHPUnit\Framework\TestCase;

/**
 * Suggested departure time from the next stop: departure + driving time + a 10-minute pause,
 * rounded UP to the next 5 minutes (never promise passengers an earlier time than realistic).
 */
final class ArrivalTimeSuggesterTest extends TestCase
{
    private function next(string $departure, int $drivingMinutes): string
    {
        return (new ArrivalTimeSuggester())
            ->nextDeparture(new \DateTimeImmutable($departure), $drivingMinutes)
            ->format('Y-m-d H:i');
    }

    public function testAddsTheDrivingTimeAndATenMinutePause(): void
    {
        self::assertSame('2026-11-12 10:15', $this->next('2026-11-12 08:00', 125));
    }

    public function testRoundsUpToTheNextFiveMinutes(): void
    {
        self::assertSame('2026-11-12 10:15', $this->next('2026-11-12 08:00', 123)); // 10:13
        self::assertSame('2026-11-12 10:15', $this->next('2026-11-12 08:00', 121)); // 10:11
    }

    public function testCrossesMidnight(): void
    {
        self::assertSame('2026-11-13 00:30', $this->next('2026-11-12 22:00', 140));
    }

    public function testKeepsTheTimezoneOfTheDeparture(): void
    {
        $next = (new ArrivalTimeSuggester())->nextDeparture(new \DateTimeImmutable('2026-11-12 08:00', new \DateTimeZone('Africa/Casablanca')), 60);

        self::assertSame('Africa/Casablanca', $next->getTimezone()->getName());
    }

    public function testANegativeDrivingTimeIsABug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ArrivalTimeSuggester())->nextDeparture(new \DateTimeImmutable('2026-11-12 08:00'), -5);
    }
}
