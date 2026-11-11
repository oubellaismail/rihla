<?php

namespace App\Tests\Unit\Service\Geo;

use App\Model\GeoPoint;
use App\Service\Geo\MeetingPointRule;
use PHPUnit\Framework\TestCase;

/**
 * A meeting point must be in or near its stop's city: at most 15 km from the city centre
 * (straight line). Beyond that, a passenger looking for « Essaouira » would wait in the wrong place.
 */
final class MeetingPointRuleTest extends TestCase
{
    private const ESSAOUIRA = [31.5085, -9.7595];
    private const AGADIR = [30.4278, -9.5981];

    private MeetingPointRule $rule;

    protected function setUp(): void
    {
        $this->rule = new MeetingPointRule();
    }

    private static function p(array $latLng): GeoPoint
    {
        return new GeoPoint($latLng[0], $latLng[1]);
    }

    public function testTheLimitIsFifteenKilometres(): void
    {
        self::assertSame(15, MeetingPointRule::MAX_KM);
    }

    public function testDistanceIsTheStraightLineOnTheEarth(): void
    {
        $km = $this->rule->distanceKm(self::p(self::AGADIR), self::p(self::ESSAOUIRA));

        self::assertEqualsWithDelta(121.0, $km, 2.0, 'Agadir to Essaouira as the crow flies');
        self::assertSame(0.0, $this->rule->distanceKm(self::p(self::AGADIR), self::p(self::AGADIR)));
    }

    public function testAMeetingPointInTheCityIsNear(): void
    {
        self::assertTrue($this->rule->isNear(new GeoPoint(31.5126, -9.77), self::p(self::ESSAOUIRA)), 'Bab Marrakech');
    }

    public function testAMeetingPointInAnotherTownIsNotNear(): void
    {
        self::assertFalse($this->rule->isNear(new GeoPoint(31.5311, -9.4383), self::p(self::ESSAOUIRA)), 'Ounagha, about 30 km away');
        self::assertFalse($this->rule->isNear(self::p(self::AGADIR), self::p(self::ESSAOUIRA)));
    }

    public function testExactlyFifteenKilometresIsStillAccepted(): void
    {
        $city = new GeoPoint(31.0, -8.0);
        $fifteenKmNorth = new GeoPoint(31.0 + 15 / 111.195, -8.0); // one degree of latitude ≈ 111.195 km

        self::assertEqualsWithDelta(15.0, $this->rule->distanceKm($city, $fifteenKmNorth), 0.01);
        self::assertTrue($this->rule->isNear(new GeoPoint(31.0 + 14.99 / 111.195, -8.0), $city));
        self::assertFalse($this->rule->isNear(new GeoPoint(31.0 + 15.1 / 111.195, -8.0), $city));
    }
}
