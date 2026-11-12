<?php

namespace App\Service\Luggage;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Enum\LuggageSize;

/**
 * Trunk volume left on a portion of a trip. Pure computation: the bookings are passed in
 * (or read from the trip), nothing touches the database.
 */
final class TrunkSpace
{
    public const MAX_BAGS_PER_PLACE = 2;
    /** Only this share of the trunk is counted: bags never pack perfectly. */
    public const USABLE_SHARE = 0.8;

    /** Volume counted for the trip, in litres (rounded down). */
    public function usableLitres(Trip $trip): int
    {
        return (int) floor($trip->getTrunkLitres() * self::USABLE_SHARE);
    }

    /**
     * Litres held by the bags on each leg; only bookings that hold seats count.
     *
     * @param iterable<Booking>|null $bookings defaults to the trip's own bookings
     *
     * @return list<int> one entry per leg
     */
    public function litresPerLeg(Trip $trip, ?iterable $bookings = null): array
    {
        $legs = max(0, $trip->getStops()->count() - 1);
        $litres = array_fill(0, $legs, 0);

        foreach ($bookings ?? $trip->getBookings() as $booking) {
            if (!$booking->getStatus()->holdsSeats()) {
                continue;
            }
            foreach (array_keys($litres) as $leg) {
                if ($booking->occupiesLeg($leg)) {
                    $litres[$leg] += $booking->getLuggageLitres();
                }
            }
        }

        return $litres;
    }

    /**
     * Litres still free on every leg of the portion: the busiest leg decides.
     *
     * @param iterable<Booking>|null $bookings defaults to the trip's own bookings
     */
    public function freeLitres(Trip $trip, int $fromPosition, int $toPosition, ?iterable $bookings = null): int
    {
        $perLeg = $this->litresPerLeg($trip, $bookings);
        $busiest = 0;
        for ($leg = $fromPosition; $leg < $toPosition; ++$leg) {
            $busiest = max($busiest, $perLeg[$leg] ?? 0);
        }

        return max(0, $this->usableLitres($trip) - $busiest);
    }

    /** What still fits in $litres, in words. */
    public function describe(int $litres): string
    {
        $large = LuggageSize::Large->litres();
        if ($litres >= 2 * $large) {
            return sprintf('place pour %d grandes valises', intdiv($litres, $large));
        }
        if ($litres >= $large) {
            return 'place pour une grande valise';
        }
        if ($litres >= LuggageSize::Medium->litres()) {
            return 'place pour une valise moyenne';
        }
        if ($litres >= LuggageSize::Cabin->litres()) {
            return 'place pour un bagage cabine';
        }

        return 'plein';
    }
}
