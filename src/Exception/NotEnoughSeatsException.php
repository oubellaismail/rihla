<?php

namespace App\Exception;

/** The requested portion of the trip has fewer free seats than asked for. */
final class NotEnoughSeatsException extends BookingNotAllowedException
{
    public function __construct(private readonly int $requestedSeats, private readonly int $freeSeats)
    {
        parent::__construct(match (true) {
            $freeSeats <= 0 => 'Plus aucune place sur cette portion du trajet.',
            1 === $freeSeats => 'Il ne reste qu\'une place sur cette portion du trajet.',
            default => sprintf('Il ne reste que %d places sur cette portion du trajet.', $freeSeats),
        });
    }

    public function getRequestedSeats(): int
    {
        return $this->requestedSeats;
    }

    public function getFreeSeats(): int
    {
        return $this->freeSeats;
    }
}
