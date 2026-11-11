<?php

namespace App\Service\Geo;

/** Suggested price of one leg: 0.35 MAD per km, rounded to the nearest 5 MAD (minimum 5 for a real distance). */
final class PriceSuggester
{
    private const MAD_PER_KM = 0.35;

    public function forDistance(int $km): int
    {
        if ($km < 0) {
            throw new \InvalidArgumentException('A distance cannot be negative.');
        }
        if (0 === $km) {
            return 0;
        }

        return max(5, (int) (round($km * self::MAD_PER_KM / 5) * 5));
    }
}
