<?php

namespace App\Tests\Unit\Service\Seat;

use App\Model\Seat;
use App\Service\Seat\SeatLayout;
use App\Tests\Support\TripBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The car seen from above (approved boards « Choisir ses places », v2):
 *   berline (up to 4 passenger seats):   A1 | B1 B2 B3
 *   monospace (5 or 6 passenger seats):  A1 | B1 B2 B3 | C1 C2
 * Seats open in comfort order A1, B1, B3, B2, C1, C2: a driver offering 3 seats in a Logan
 * keeps the back middle seat closed.
 */
final class SeatLayoutTest extends TestCase
{
    private SeatLayout $layout;

    protected function setUp(): void
    {
        $this->layout = new SeatLayout();
    }

    /** @param list<Seat> $seats @return list<string> */
    private static function codes(array $seats): array
    {
        return array_map(static fn (Seat $s) => $s->code, $seats);
    }

    public static function kinds(): iterable
    {
        yield '1 seat' => [1, SeatLayout::SEDAN];
        yield '4 seats' => [4, SeatLayout::SEDAN];
        yield '5 seats' => [5, SeatLayout::VAN];
        yield '6 seats' => [6, SeatLayout::VAN];
    }

    #[DataProvider('kinds')]
    public function testUpToFourPassengerSeatsIsABerlineAboveIsAMonospace(int $passengerSeats, string $kind): void
    {
        self::assertSame($kind, $this->layout->kind($passengerSeats));
    }

    public function testTheBerlinePlanInPlanOrder(): void
    {
        $seats = $this->layout->seats(4);

        self::assertSame(['A1', 'B1', 'B2', 'B3'], self::codes($seats));
        self::assertSame(['A', 'B', 'B', 'B'], array_map(static fn (Seat $s) => $s->row, $seats));
        self::assertSame('Avant, côté passager', $seats[0]->position);
        self::assertSame('Arrière gauche, fenêtre', $seats[1]->position);
        self::assertSame('Arrière milieu', $seats[2]->position);
        self::assertSame('Arrière droite, fenêtre', $seats[3]->position);
    }

    public function testTheMonospacePlanHasAThirdRow(): void
    {
        $seats = $this->layout->seats(6);

        self::assertSame(['A1', 'B1', 'B2', 'B3', 'C1', 'C2'], self::codes($seats));
        self::assertSame('Rang 2, gauche, fenêtre', $seats[1]->position);
        self::assertSame('Rang 3, droite', $seats[5]->position);
    }

    public function testComfortOrderLeavesTheMiddleSeatForLast(): void
    {
        self::assertSame(['A1', 'B1', 'B3', 'B2'], $this->layout->comfortOrder(4));
        self::assertSame(['A1', 'B1', 'B3', 'B2', 'C1', 'C2'], $this->layout->comfortOrder(6));
    }

    public function testTheOfferedSeatsAreTheFirstOnesInComfortOrder(): void
    {
        self::assertSame(['A1', 'B1', 'B3'], $this->layout->offeredCodes(B::trip(seatsOffered: 3)));
        self::assertSame(['A1', 'B1', 'B3', 'B2'], $this->layout->offeredCodes(B::trip(seatsOffered: 4)));
        self::assertSame(['A1'], $this->layout->offeredCodes(B::trip(seatsOffered: 1)));
        self::assertSame(['A1', 'B1', 'B3', 'B2', 'C1'], $this->layout->offeredCodes(B::trip(seatsOffered: 5, passengerSeats: 6)));
    }

    public function testNeverMoreSeatsThanThePlanHas(): void
    {
        self::assertSame(['A1', 'B1', 'B3', 'B2'], $this->layout->offeredCodes(B::trip(seatsOffered: 6, passengerSeats: 4)));
    }
}
