<?php

namespace App\Service\Luggage;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Enum\LuggageSize;

/**
 * The driver's trunk plan, stop by stop: which bags to load and unload, and how full the trunk is
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
     *     load: list<array{name: string, bags: string}>,
     *     unload: list<string>,
     *     next: ?array{city: string, litres: int, bags: int}
     * }>}
     */
    public function build(Trip $trip): array
    {
        $bookings = array_values(array_filter(
            $trip->getBookings()->toArray(),
            static fn (Booking $b): bool => $b->getStatus()->holdsSeats() && $b->getBagCount() > 0,
        ));
        $stops = $trip->getStops()->toArray();
        $litresPerLeg = $this->trunkSpace->litresPerLeg($trip, $bookings);

        $plan = [];
        foreach ($stops as $i => $stop) {
            $position = $stop->getPosition();
            $load = [];
            $unload = [];
            foreach ($bookings as $booking) {
                $name = (string) $booking->getPassenger()->getFirstName();
                if ($booking->getFromStop()->getPosition() === $position) {
                    $load[] = ['name' => $name, 'bags' => LuggageSize::describeBags($booking->getBags())];
                }
                if ($booking->getToStop()->getPosition() === $position) {
                    $unload[] = $name;
                }
            }

            $next = null;
            if (isset($stops[$i + 1])) {
                $bagCount = 0;
                foreach ($bookings as $booking) {
                    if ($booking->occupiesLeg($position)) {
                        $bagCount += $booking->getBagCount();
                    }
                }
                $next = ['city' => $stops[$i + 1]->getCity()->getName(), 'litres' => $litresPerLeg[$position] ?? 0, 'bags' => $bagCount];
            }

            $plan[] = ['stop' => $stop, 'load' => $load, 'unload' => $unload, 'next' => $next];
        }

        return [
            'usable' => $this->trunkSpace->usableLitres($trip),
            'trunk' => $trip->getTrunkLitres(),
            'hasBags' => [] !== $bookings,
            'stops' => $plan,
        ];
    }
}
