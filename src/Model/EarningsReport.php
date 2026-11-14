<?php

namespace App\Model;

use App\Entity\Trip;

/** What a driver earned, will earn and how full the car was. Amounts in MAD. */
final readonly class EarningsReport
{
    /**
     * @param list<array{month: \DateTimeImmutable, earned: int, upcoming: int}>                                   $months last six calendar months, oldest first
     * @param list<array{trip: Trip, state: 'upcoming'|'completed'|'cancelled', amount: int, seats: int, legs: list<int>}> $rows   one per trip, most recent departure first
     */
    public function __construct(
        public int $earned,
        public int $upcoming,
        public int $seatsSold,
        public int $tripsWithSales,
        public int $resoldSeats,
        public ?int $fillRate,
        public array $months,
        public array $rows,
        public int $upcomingBookings = 0,
    ) {
    }
}
