<?php

namespace App\Service;

use App\Model\RatingSummary;
use App\Model\SearchCriteria;
use App\Model\TripMatch;
use App\Service\Luggage\TrunkSpace;

/** Filters and sorts the rows of the search results (a TripMatch and the driver's rating). */
final class SearchFilter
{
    private const MIN_RATING = 4.0;

    public function __construct(private readonly TrunkSpace $trunkSpace)
    {
    }

    /**
     * @param list<array{match: TripMatch, rating: RatingSummary}> $rows in departure order
     * @param bool $byCheapestLeg compare the cheapest leg with a seat (upcoming list) instead of the price of the portion
     *
     * @return list<array{match: TripMatch, rating: RatingSummary}>
     */
    public function apply(array $rows, SearchCriteria $criteria, bool $byCheapestLeg = false): array
    {
        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->keeps($row['match'], $row['rating'], $criteria, $byCheapestLeg)));

        if ('departure' === $criteria->sort) {
            return $rows; // already in departure order, full matches last
        }

        // usort is stable: ties keep the departure order.
        usort($rows, function (array $a, array $b) use ($criteria, $byCheapestLeg): int {
            $byFull = $a['match']->full <=> $b['match']->full;
            if (0 !== $byFull) {
                return $byFull;
            }

            return 'price' === $criteria->sort
                ? $this->price($a['match'], $byCheapestLeg) <=> $this->price($b['match'], $byCheapestLeg)
                : ($b['rating']->average ?? -1.0) <=> ($a['rating']->average ?? -1.0);
        });

        return $rows;
    }

    private function keeps(TripMatch $match, RatingSummary $rating, SearchCriteria $criteria, bool $byCheapestLeg): bool
    {
        if ([] !== $criteria->times && !\in_array($this->slot((int) $match->from->getDepartureAt()->format('G')), $criteria->times, true)) {
            return false;
        }
        if (null !== $criteria->maxPrice && $this->price($match, $byCheapestLeg) > $criteria->maxPrice) {
            return false;
        }
        if ($criteria->rated && (null === $rating->average || $rating->average < self::MIN_RATING)) {
            return false;
        }
        if (null !== $criteria->bag) {
            $free = $this->trunkSpace->freeLitres($match->trip, $match->from->getPosition(), $match->to->getPosition());
            if ($free < $criteria->bag->litres()) {
                return false;
            }
        }

        return true;
    }

    private function price(TripMatch $match, bool $byCheapestLeg): int
    {
        return $byCheapestLeg ? $match->cheapestLegPrice : $match->pricePerSeat;
    }

    private function slot(int $hour): string
    {
        return match (true) {
            $hour < 12 => 'morning',
            $hour < 18 => 'afternoon',
            default => 'evening',
        };
    }
}
