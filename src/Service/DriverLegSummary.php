<?php

namespace App\Service;

use App\Entity\Trip;

/**
 * Per-leg wording for the driver side: which portions of a trip still have a free seat.
 *
 * Computed once here and used by the driver list and the driver trip page.
 */
final class DriverLegSummary
{
    public function __construct(private readonly SeatAvailabilityCalculator $calculator)
    {
    }

    /**
     * @return array{
     *     taken: list<int>,
     *     free: list<int>,
     *     passengers: int,
     *     maxTaken: int,
     *     allFree: bool,
     *     full: bool,
     *     runs: list<array{from: string, to: string, free: int}>,
     * }
     */
    public function summarise(Trip $trip): array
    {
        $stops = array_values($trip->getStops()->toArray());
        $taken = $this->calculator->occupiedPerLeg(max(0, \count($stops) - 1), $trip->getBookings());
        $free = array_map(static fn (int $t) => $trip->getSeatsOffered() - $t, $taken);

        $passengers = 0;
        foreach ($trip->getBookings() as $booking) {
            if ($booking->getStatus()->occupiedSeat()) {
                $passengers += $booking->getSeats();
            }
        }

        $runs = [];
        $start = null;
        foreach ([...$free, 0] as $i => $legFree) {
            if ($legFree >= 1) {
                $start ??= $i;
                continue;
            }
            if (null !== $start) {
                $runs[] = [
                    'from' => $stops[$start]->getCity()->getName(),
                    'to' => $stops[$i]->getCity()->getName(),
                    'free' => min(\array_slice($free, $start, $i - $start)),
                ];
                $start = null;
            }
        }

        $freeLegs = \count(array_filter($free, static fn (int $f) => $f >= 1));

        return [
            'taken' => $taken,
            'free' => $free,
            'passengers' => $passengers,
            'maxTaken' => [] === $taken ? 0 : max($taken),
            'allFree' => [] !== $free && $freeLegs === \count($free),
            'full' => [] !== $free && 0 === $freeLegs,
            'runs' => $runs,
        ];
    }
}
