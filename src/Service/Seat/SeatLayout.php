<?php

namespace App\Service\Seat;

use App\Entity\Trip;
use App\Model\Seat;

/**
 * The car seen from above: a berline (up to 4 passenger seats) has A1 | B1 B2 B3,
 * a monospace (5 or 6) adds a third row C1 C2. Seats open in comfort order
 * (window seats before the middle one), so a driver offering 3 seats keeps B2 closed.
 */
final class SeatLayout
{
    public const SEDAN = 'berline';
    public const VAN = 'monospace';

    private const MAX_SEDAN_SEATS = 4;

    public function kind(int $passengerSeats): string
    {
        return $passengerSeats <= self::MAX_SEDAN_SEATS ? self::SEDAN : self::VAN;
    }

    /** @return list<Seat> in plan order */
    public function seats(int $passengerSeats): array
    {
        $seats = [
            new Seat('A1', 'A', 'Avant, côté passager'),
        ];
        if (self::SEDAN === $this->kind($passengerSeats)) {
            return [...$seats,
                new Seat('B1', 'B', 'Arrière gauche, fenêtre'),
                new Seat('B2', 'B', 'Arrière milieu'),
                new Seat('B3', 'B', 'Arrière droite, fenêtre'),
            ];
        }

        return [...$seats,
            new Seat('B1', 'B', 'Rang 2, gauche, fenêtre'),
            new Seat('B2', 'B', 'Rang 2, milieu'),
            new Seat('B3', 'B', 'Rang 2, droite, fenêtre'),
            new Seat('C1', 'C', 'Rang 3, gauche'),
            new Seat('C2', 'C', 'Rang 3, droite'),
        ];
    }

    /** @return list<string> */
    public function comfortOrder(int $passengerSeats): array
    {
        return self::SEDAN === $this->kind($passengerSeats)
            ? ['A1', 'B1', 'B3', 'B2']
            : ['A1', 'B1', 'B3', 'B2', 'C1', 'C2'];
    }

    /**
     * The seats the driver sells: the first ones in comfort order, as many as seats offered.
     *
     * @return list<string>
     */
    public function offeredCodes(Trip $trip): array
    {
        $order = $this->comfortOrder($trip->getVehicle()->getPassengerSeats());

        return array_slice($order, 0, max(0, $trip->getSeatsOffered()));
    }
}
