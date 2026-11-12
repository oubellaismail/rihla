<?php

namespace App\Tests\Unit\Enum;

use App\Entity\Booking;
use App\Enum\LuggageSize;
use PHPUnit\Framework\TestCase;

/**
 * Bag sizes a passenger can declare (board « Vos bagages, voyageur »). A handbag or small
 * backpack is never declared: it travels with the passenger.
 */
final class LuggageSizeTest extends TestCase
{
    public function testThreeSizesFromSmallestToLargest(): void
    {
        self::assertSame(['cabin', 'medium', 'large'], array_map(static fn (LuggageSize $s) => $s->value, LuggageSize::cases()));
        self::assertSame([40, 70, 100], array_map(static fn (LuggageSize $s) => $s->litres(), LuggageSize::cases()));
        self::assertSame(['Bagage cabine', 'Valise moyenne', 'Grande valise'], array_map(static fn (LuggageSize $s) => $s->label(), LuggageSize::cases()));
    }

    public function testDescribeBagsSpellsTheCountsInFrench(): void
    {
        self::assertSame('1 valise moyenne', LuggageSize::describeBags(['medium' => 1]));
        self::assertSame('2 grandes valises', LuggageSize::describeBags(['large' => 2]));
        self::assertSame('1 bagage cabine, 1 grande valise', LuggageSize::describeBags(['large' => 1, 'cabin' => 1]), 'smallest first');
        self::assertSame('2 bagages cabine', LuggageSize::describeBags(['cabin' => 2, 'medium' => 0]));
        self::assertSame('Aucun bagage en soute', LuggageSize::describeBags([]));
    }

    public function testABookingKeepsItsBagsAndTheirVolume(): void
    {
        $booking = (new Booking())->setBags(['large' => 1, 'cabin' => 1]);

        self::assertSame(['cabin' => 1, 'medium' => 0, 'large' => 1], $booking->getBags(), 'every size, smallest first');
        self::assertSame(140, $booking->getLuggageLitres());
        self::assertSame(2, $booking->getBagCount());
    }

    public function testANewBookingHasNoBags(): void
    {
        $booking = new Booking();

        self::assertSame(['cabin' => 0, 'medium' => 0, 'large' => 0], $booking->getBags());
        self::assertSame(0, $booking->getLuggageLitres());
    }
}
