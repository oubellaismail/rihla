<?php

namespace App\Tests\Unit\Service;

use App\Service\PriceCalculator;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Leg prices of the default trip: Agadir→Essaouira 60, Essaouira→Marrakech 65, Marrakech→Casablanca 85. */
final class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PriceCalculator();
    }

    public function testPriceOfASingleLeg(): void
    {
        $trip = B::trip();

        self::assertSame(65, $this->calculator->portionPrice($trip, B::stop($trip, 1), B::stop($trip, 2), 1));
    }

    public function testPriceIsTheSumOfTheLegsTimesTheSeats(): void
    {
        $trip = B::trip();

        self::assertSame(210, $this->calculator->portionPrice($trip, B::stop($trip, 0), B::stop($trip, 3), 1));
        self::assertSame(300, $this->calculator->portionPrice($trip, B::stop($trip, 1), B::stop($trip, 3), 2));
    }

    public function testReversedPortionIsRejected(): void
    {
        $trip = B::trip();

        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->portionPrice($trip, B::stop($trip, 2), B::stop($trip, 1), 1);
    }

    public function testStopsFromAnotherTripAreRejected(): void
    {
        $trip = B::trip();
        $other = B::trip();

        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->portionPrice($trip, B::stop($trip, 0), B::stop($other, 2), 1);
    }

    public function testAtLeastOneSeat(): void
    {
        $trip = B::trip();

        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->portionPrice($trip, B::stop($trip, 0), B::stop($trip, 1), 0);
    }

    public function testALegWithoutAPriceIsRejected(): void
    {
        $trip = B::trip(3, [
            ['Agadir', '08:00', null],
            ['Tiznit', '09:30', null],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->portionPrice($trip, B::stop($trip, 0), B::stop($trip, 1), 1);
    }

    /** @return iterable<string, array{int, int}> */
    public static function suggestedPrices(): iterable
    {
        // 0.35 MAD per km, rounded to the nearest 5 MAD, at least 5 MAD.
        yield 'Agadir → Essaouira 175 km' => [175, 60];   // 61.25
        yield 'Essaouira → Marrakech 190 km' => [190, 65]; // 66.5
        yield 'Marrakech → Casablanca 240 km' => [240, 85]; // 84
        yield 'Agadir → Marrakech 250 km' => [250, 90];   // 87.5, half rounds up
        yield 'very short 10 km' => [10, 5];               // 3.5
        yield 'minimum 1 km' => [1, 5];                    // 0.35, floor at 5
        yield 'no distance' => [0, 0];
    }

    #[DataProvider('suggestedPrices')]
    public function testSuggestedLegPrice(int $km, int $expected): void
    {
        self::assertSame($expected, $this->calculator->suggestedLegPrice($km));
    }

    public function testNegativeDistanceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->suggestedLegPrice(-5);
    }
}
