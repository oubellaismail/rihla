<?php

namespace App\Service;

use App\Entity\Trip;
use App\Entity\TripStop;

/**
 * Computes passenger prices (in MAD) for portions of a trip and suggests
 * leg prices from a road distance.
 */
final class PriceCalculator
{
    /** Suggested price per km, expressed in hundredths of MAD (0.35 MAD). */
    public const PRICE_PER_KM_CENTS = 35;

    /** Suggested prices are rounded to the nearest multiple of this many MAD. */
    public const ROUNDING_STEP = 5;

    /** Minimum suggested price in MAD for any non-zero distance. */
    public const MINIMUM_PRICE = 5;

    /**
     * Price in MAD for riding from one stop to another: the sum of the leg
     * prices in [from, to) multiplied by the number of seats.
     *
     * @throws \InvalidArgumentException on invalid seats, foreign or reversed stops, or an unpriced leg
     */
    public function portionPrice(Trip $trip, TripStop $from, TripStop $to, int $seats): int
    {
        if ($seats < 1) {
            throw new \InvalidArgumentException('At least one seat is required.');
        }
        if ($from->getTrip() !== $trip || $to->getTrip() !== $trip) {
            throw new \InvalidArgumentException('Both stops must belong to the trip.');
        }

        $fromPosition = $from->getPosition();
        $toPosition = $to->getPosition();
        if ($fromPosition >= $toPosition) {
            throw new \InvalidArgumentException('The departure stop must come before the arrival stop.');
        }

        $total = 0;
        foreach ($trip->getStops() as $stop) {
            $position = $stop->getPosition();
            if ($position < $fromPosition || $position >= $toPosition) {
                continue;
            }

            $legPrice = $stop->getLegPriceToNext();
            if (null === $legPrice) {
                throw new \InvalidArgumentException(sprintf('The leg at position %d has no price.', $position));
            }
            $total += $legPrice;
        }

        return $total * $seats;
    }

    /**
     * Suggests a price in MAD per seat for a leg of the given length, rounded
     * to the nearest 5 MAD (half rounds up) with a minimum of 5 MAD.
     *
     * @throws \InvalidArgumentException when the distance is negative
     */
    public function suggestedLegPrice(int $km): int
    {
        if ($km < 0) {
            throw new \InvalidArgumentException('The distance cannot be negative.');
        }
        if (0 === $km) {
            return 0;
        }

        // Integer arithmetic in hundredths of MAD avoids float rounding errors.
        $cents = $km * self::PRICE_PER_KM_CENTS;
        $stepCents = self::ROUNDING_STEP * 100;
        $rounded = intdiv($cents + intdiv($stepCents, 2), $stepCents) * self::ROUNDING_STEP;

        return max(self::MINIMUM_PRICE, $rounded);
    }
}
