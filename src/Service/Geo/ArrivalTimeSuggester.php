<?php

namespace App\Service\Geo;

/** Suggested departure from the next stop: driving time + a 10-minute pause, rounded up to 5 minutes. */
final class ArrivalTimeSuggester
{
    private const PAUSE_MINUTES = 10;

    public function nextDeparture(\DateTimeImmutable $departure, int $drivingMinutes): \DateTimeImmutable
    {
        if ($drivingMinutes < 0) {
            throw new \InvalidArgumentException('A driving time cannot be negative.');
        }

        $next = $departure->modify(sprintf('+%d minutes', $drivingMinutes + self::PAUSE_MINUTES));
        $remainder = (int) $next->format('i') % 5;
        if (0 !== $remainder || 0 !== (int) $next->format('s')) {
            $next = $next->setTime((int) $next->format('H'), (int) $next->format('i') - $remainder + 5, 0);
        }

        return $next;
    }
}
