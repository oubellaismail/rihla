<?php

namespace App\Exception;

/** Seats the passenger chose were booked by someone else in the meantime. */
final class SeatTakenException extends BookingNotAllowedException
{
    /** @param list<string> $seatCodes */
    public function __construct(private readonly array $seatCodes)
    {
        $last = array_pop($seatCodes);
        parent::__construct([] === $seatCodes
            ? sprintf("La place %s vient d'être réservée.", $last)
            : sprintf("Les places %s et %s viennent d'être réservées.", implode(', ', $seatCodes), $last));
    }

    /** @return list<string> */
    public function getSeatCodes(): array
    {
        return $this->seatCodes;
    }
}
