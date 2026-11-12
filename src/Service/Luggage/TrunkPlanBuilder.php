<?php

namespace App\Service\Luggage;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Enum\LuggageSize;

/**
 * The driver's trunk checklist, stop by stop: first the bags to hand back, then the bags to
 * put in (those going furthest first, so they end up at the back), then how full the trunk is
 * until the next stop. Only bookings that hold seats count; passengers without bags are left out.
 */
final class TrunkPlanBuilder
{
    public function __construct(private readonly TrunkSpace $trunkSpace)
    {
    }

    /**
     * @return array{usable: int, trunk: int, hasBags: bool, stops: list<array{
     *     stop: \App\Entity\TripStop,
     *     unload: list<array{name: string, bags: string, sizes: list<string>}>,
     *     load: list<array{name: string, bags: string, sizes: list<string>, to: string, back: bool}>,
     *     next: ?array{city: string, litres: int, bags: int, fullness: string, room: string}
     * }>}
     */
    public function build(Trip $trip): array
    {
        $bookings = array_values(array_filter(
            $trip->getBookings()->toArray(),
            static fn (Booking $b): bool => $b->getStatus()->holdsSeats() && $b->getBagCount() > 0,
        ));
        $stops = $trip->getStops()->toArray();
        $usable = $this->trunkSpace->usableLitres($trip);
        $litresPerLeg = $this->trunkSpace->litresPerLeg($trip, $bookings);

        $plan = [];
        foreach ($stops as $i => $stop) {
            $position = $stop->getPosition();
            $load = [];
            $unload = [];
            foreach ($bookings as $booking) {
                if ($booking->getFromStop()->getPosition() === $position) {
                    $load[] = $this->line($booking) + [
                        'to' => (string) $booking->getToStop()->getCity()->getName(),
                        'toPosition' => $booking->getToStop()->getPosition(),
                    ];
                }
                if ($booking->getToStop()->getPosition() === $position) {
                    $unload[] = $this->line($booking);
                }
            }

            // Going furthest first: those bags go in first, at the back of the trunk.
            usort($load, static fn (array $a, array $b): int => $b['toPosition'] <=> $a['toPosition']);
            $furthest = $load[0]['toPosition'] ?? null;
            $several = \count(array_unique(array_column($load, 'toPosition'))) > 1;
            $load = array_map(static function (array $l) use ($furthest, $several): array {
                $l['back'] = $several && $l['toPosition'] === $furthest;
                unset($l['toPosition']);

                return $l;
            }, $load);

            $next = null;
            if (isset($stops[$i + 1])) {
                $bagCount = 0;
                foreach ($bookings as $booking) {
                    if ($booking->occupiesLeg($position)) {
                        $bagCount += $booking->getBagCount();
                    }
                }
                $litres = $litresPerLeg[$position] ?? 0;
                $next = [
                    'city' => $stops[$i + 1]->getCity()->getName(),
                    'litres' => $litres,
                    'bags' => $bagCount,
                    'fullness' => $this->fullness($litres, $usable),
                    'room' => $this->trunkSpace->describe(max(0, $usable - $litres)),
                ];
            }

            $plan[] = ['stop' => $stop, 'unload' => $unload, 'load' => $load, 'next' => $next];
        }

        return [
            'usable' => $usable,
            'trunk' => $trip->getTrunkLitres(),
            'hasBags' => [] !== $bookings,
            'stops' => $plan,
        ];
    }

    /** @return array{name: string, bags: string, sizes: list<string>} largest bag first */
    private function line(Booking $booking): array
    {
        $sizes = [];
        foreach (array_reverse(LuggageSize::cases()) as $size) {
            for ($n = 0; $n < $booking->getBags()[$size->value]; ++$n) {
                $sizes[] = $size->value;
            }
        }

        return [
            'name' => (string) $booking->getPassenger()->getFirstName(),
            'bags' => LuggageSize::describeBags($booking->getBags()),
            'sizes' => $sizes,
        ];
    }

    /** How full the trunk is, in the words a driver uses. */
    private function fullness(int $litres, int $usable): string
    {
        if (0 === $litres) {
            return 'Coffre vide';
        }
        $share = $usable > 0 ? $litres / $usable : 1;

        return match (true) {
            $share < 0.4 => 'Coffre peu chargé',
            $share < 0.7 => 'Coffre à moitié plein',
            $share < 0.95 => 'Coffre presque plein',
            default => 'Coffre plein',
        };
    }
}
