<?php

namespace App\Tests\Unit\Service\Luggage;

use App\Enum\BookingStatus;
use App\Service\Luggage\TrunkPlanBuilder;
use App\Service\Luggage\TrunkSpace;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The driver's trunk checklist (DECISIONS « The driver's trunk as a checklist »).
 * Trip: Agadir(0) → Essaouira(1) → Marrakech(2) → Casablanca(3), Dacia Logan 530 L, 424 L counted.
 * At each stop: the bags to hand back, then the bags to put in (furthest first), then how full
 * the trunk is until the next stop.
 */
final class TrunkPlanBuilderTest extends TestCase
{
    private TrunkPlanBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TrunkPlanBuilder(new TrunkSpace());
    }

    public function testEachStopSaysWhatToHandBackAndWhatToPutIn(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 1, passenger: B::user('Mehdi'), bags: ['large' => 1]);
        B::booking($trip, 1, 2, passenger: B::user('Salma'), bags: ['medium' => 1]);

        $essaouira = $this->builder->build($trip)['stops'][1];

        self::assertSame([['name' => 'Mehdi', 'bags' => '1 grande valise', 'sizes' => ['large']]], $essaouira['unload']);
        self::assertSame([['name' => 'Salma', 'bags' => '1 valise moyenne', 'sizes' => ['medium'], 'to' => 'Marrakech', 'back' => false]], $essaouira['load']);
    }

    public function testBagsGoingFurthestComeFirstAndGoAtTheBack(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 1, passenger: B::user('Youssef'), bags: ['cabin' => 1]);
        B::booking($trip, 0, 3, passenger: B::user('Rachid'), bags: ['large' => 1]);
        B::booking($trip, 0, 2, passenger: B::user('Lina'), bags: ['medium' => 1]);

        $load = $this->builder->build($trip)['stops'][0]['load'];

        self::assertSame(['Rachid', 'Lina', 'Youssef'], array_column($load, 'name'));
        self::assertSame(['Casablanca', 'Marrakech', 'Essaouira'], array_column($load, 'to'));
        self::assertSame([true, false, false], array_column($load, 'back'));
    }

    public function testNothingGoesAtTheBackWhenEveryoneGetsOffAtTheSameStop(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 1, passenger: B::user('Youssef'), bags: ['cabin' => 1]);
        B::booking($trip, 0, 1, passenger: B::user('Mehdi'), bags: ['large' => 1]);

        $load = $this->builder->build($trip)['stops'][0]['load'];

        self::assertSame([false, false], array_column($load, 'back'));
    }

    public function testOneIconPerBagLargestFirst(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, 2, passenger: B::user('Omar'), bags: ['cabin' => 1, 'large' => 1, 'medium' => 1]);

        self::assertSame(['large', 'medium', 'cabin'], $this->builder->build($trip)['stops'][0]['load'][0]['sizes']);
    }

    public function testCancelledBookingsAndBookingsWithoutBagsAreLeftOut(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, status: BookingStatus::CancelledByPassenger, passenger: B::user('Rachid'), bags: ['large' => 1]);
        B::booking($trip, 0, 3, passenger: B::user('Lina'));

        $plan = $this->builder->build($trip);

        self::assertFalse($plan['hasBags']);
        self::assertSame([], $plan['stops'][0]['load']);
        self::assertSame('Coffre vide', $plan['stops'][0]['next']['fullness']);
    }

    public static function fullness(): iterable
    {
        yield 'one large of 424 L' => [530, ['large' => 1], 1, 'Coffre peu chargé'];
        yield '170 of 424 L, 40 %' => [530, ['large' => 1, 'medium' => 1], 1, 'Coffre à moitié plein'];
        yield '300 of 424 L, 71 %' => [530, ['large' => 3], 2, 'Coffre presque plein'];
        yield '400 of 424 L, 94 %' => [530, ['large' => 4], 2, 'Coffre presque plein'];
        yield '400 of 400 L' => [500, ['large' => 4], 2, 'Coffre plein'];
    }

    /** @param array<string, int> $bags */
    #[DataProvider('fullness')]
    public function testHowFullTheTrunkIsInWords(int $trunkLitres, array $bags, int $seats, string $words): void
    {
        $trip = B::trip(trunkLitres: $trunkLitres);
        B::booking($trip, 0, 1, $seats, passenger: B::user('Rachid'), bags: $bags);

        self::assertSame($words, $this->builder->build($trip)['stops'][0]['next']['fullness']);
    }

    public function testEveryStopButTheLastSaysHowFullTheTrunkIsUntilTheNext(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, passenger: B::user('Rachid'), bags: ['large' => 1]);

        $stops = $this->builder->build($trip)['stops'];

        self::assertSame(
            ['city' => 'Essaouira', 'litres' => 100, 'bags' => 1, 'fullness' => 'Coffre peu chargé', 'room' => 'place pour 3 grandes valises'],
            $stops[0]['next'],
        );
        self::assertNull($stops[3]['next']);
        self::assertSame(['Rachid'], array_column($stops[3]['unload'], 'name'));
    }
}
