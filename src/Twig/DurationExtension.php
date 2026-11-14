<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** « 2 h 15 », « 2 h », « 45 min »: the time between two moments. */
final class DurationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('duration', $this->format(...))];
    }

    public function format(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        $minutes = max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60));
        [$h, $m] = [intdiv($minutes, 60), $minutes % 60];

        return match (true) {
            0 === $h => $m.' min',
            0 === $m => $h.' h',
            default => sprintf('%d h %02d', $h, $m),
        };
    }
}
