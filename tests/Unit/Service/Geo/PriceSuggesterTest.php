<?php

namespace App\Tests\Unit\Service\Geo;

use App\Service\Geo\PriceSuggester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Suggested price for one leg: 0.35 MAD per km, rounded to the nearest 5 MAD.
 * The driver may type another price; this is only a hint on the publish page.
 */
final class PriceSuggesterTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function legs(): iterable
    {
        yield 'Agadir → Essaouira, 175 km (61.25)' => [175, 60];
        yield 'Essaouira → Marrakech, 190 km (66.5)' => [190, 65];
        yield 'Marrakech → Casablanca, 240 km (84)' => [240, 85];
        yield 'exactly on a multiple of 5' => [100, 35];
        yield 'halfway between 15 and 20 rounds up (17.5)' => [50, 20];
        yield 'a short hop still costs something' => [1, 5];
        yield 'zero km is free' => [0, 0];
    }

    #[DataProvider('legs')]
    public function testSuggestsThePriceOfALeg(int $km, int $expected): void
    {
        self::assertSame($expected, (new PriceSuggester())->forDistance($km));
    }

    public function testANegativeDistanceIsABug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PriceSuggester())->forDistance(-1);
    }
}
