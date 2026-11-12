<?php

namespace App\Tests\Unit\Service\Luggage;

use App\Enum\BookingStatus;
use App\Service\Luggage\TrunkSpace;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trunk space on a portion of the trip (luggage.drawio).
 * Trip: Agadir(0) → Essaouira(1) → Marrakech(2) → Casablanca(3), Dacia Logan 530 L,
 * of which 80 % (424 L) is counted. A booking from stop a to stop b holds its bags on legs a .. b-1.
 * Bags: cabin 40 L, medium 70 L, large 100 L.
 */
final class TrunkSpaceTest extends TestCase
{
    private TrunkSpace $trunk;

    protected function setUp(): void
    {
        $this->trunk = new TrunkSpace();
    }

    public function testEightyPercentOfTheTrunkIsUsable(): void
    {
        self::assertSame(424, $this->trunk->usableLitres(B::trip()));
        self::assertSame(312, $this->trunk->usableLitres(B::trip(trunkLitres: 390)), 'Clio, rounded down');
        self::assertSame(0, $this->trunk->usableLitres(B::trip(trunkLitres: 0)));
    }

    public function testTheTripVolumeComesFromTheVehicleUnlessTheDriverLoweredIt(): void
    {
        $trip = B::trip();
        self::assertSame(530, $trip->getTrunkLitres());

        $trip->setTrunkLitres(300);

        self::assertSame(300, $trip->getTrunkLitres());
        self::assertSame(240, $this->trunk->usableLitres($trip));
    }

    public function testLitresPerLegFollowEachBookingsPortion(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, bags: ['large' => 1]);                 // Rachid, whole trip
        B::booking($trip, 0, 1, bags: ['large' => 1, 'cabin' => 1]);   // gets off at Essaouira
        B::booking($trip, 1, 2, bags: ['medium' => 1]);                // Salma
        B::booking($trip, 2, 3, bags: ['medium' => 2]);                // Omar

        self::assertSame([240, 170, 240], $this->trunk->litresPerLeg($trip));
    }

    public function testFreeLitresOnAPortionIsDecidedByItsFullestLeg(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 1, bags: ['large' => 2]); // 200 L on leg 0 only
        B::booking($trip, 2, 3, bags: ['large' => 1]); // 100 L on leg 2 only

        self::assertSame(224, $this->trunk->freeLitres($trip, 0, 3));
        self::assertSame(424, $this->trunk->freeLitres($trip, 1, 2), 'unloaded at Essaouira, loaded again at Marrakech');
        self::assertSame(324, $this->trunk->freeLitres($trip, 1, 3));
    }

    public function testCancelledBookingsGiveTheirLitresBack(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, status: BookingStatus::CancelledByPassenger, bags: ['large' => 2]);
        B::booking($trip, 0, 3, status: BookingStatus::CancelledByDriver, bags: ['large' => 2]);

        self::assertSame(424, $this->trunk->freeLitres($trip, 0, 3));
    }

    public function testBookingsWithoutBagsTakeNoRoom(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, 2);

        self::assertSame(424, $this->trunk->freeLitres($trip, 0, 3));
    }

    public function testFreeLitresNeverGoBelowZero(): void
    {
        $trip = B::trip();
        B::booking($trip, 0, 3, 2, bags: ['large' => 4]); // booked before the driver lowered the trunk
        $trip->setTrunkLitres(200);

        self::assertSame(0, $this->trunk->freeLitres($trip, 0, 3));
    }

    public function testTheBookingsCanBePassedIn(): void
    {
        $trip = B::trip();
        $other = B::trip();
        $booking = B::booking($other, 0, 3, bags: ['large' => 1]);

        self::assertSame(324, $this->trunk->freeLitres($trip, 0, 3, [$booking]), 'the fresh bookings read under the lock win');
    }

    public static function descriptions(): iterable
    {
        yield 'empty Logan' => [424, 'place pour 4 grandes valises'];
        yield 'two large' => [254, 'place pour 2 grandes valises'];
        yield 'one large' => [100, 'place pour une grande valise'];
        yield 'one medium' => [99, 'place pour une valise moyenne'];
        yield 'medium, exactly' => [70, 'place pour une valise moyenne'];
        yield 'cabin' => [69, 'place pour un bagage cabine'];
        yield 'cabin, exactly' => [40, 'place pour un bagage cabine'];
        yield 'full' => [39, 'plein'];
        yield 'nothing' => [0, 'plein'];
    }

    #[DataProvider('descriptions')]
    public function testDescribeTellsWhatStillFits(int $litres, string $text): void
    {
        self::assertSame($text, $this->trunk->describe($litres));
    }

    public function testAtMostTwoCountedBagsPerPlace(): void
    {
        self::assertSame(2, TrunkSpace::MAX_BAGS_PER_PLACE);
    }
}
