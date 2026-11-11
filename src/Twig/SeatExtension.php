<?php

namespace App\Twig;

use App\Entity\Booking;
use App\Service\Seat\SeatMap;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Seat codes in French sentences: « A1 et B3 », and the seats a booking holds. */
final class SeatExtension extends AbstractExtension
{
    public function __construct(private readonly SeatMap $seatMap)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('seat_list', $this->list(...))];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('seats_of', $this->seatsOf(...))];
    }

    /** @param list<string> $codes */
    public function list(array $codes): string
    {
        if (count($codes) <= 1) {
            return implode('', $codes);
        }

        return implode(', ', array_slice($codes, 0, -1)).' et '.$codes[array_key_last($codes)];
    }

    /** @return list<string> */
    public function seatsOf(Booking $booking): array
    {
        return $this->seatMap->seatsOf($booking->getTrip(), $booking);
    }
}
