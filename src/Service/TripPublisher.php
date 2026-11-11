<?php

namespace App\Service;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Exception\TripNotPublishableException;
use App\Service\Geo\RouteEnricher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Validates a trip built by the "Publier un trajet" form and saves it as published.
 */
final class TripPublisher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly RouteEnricher $routeEnricher,
    ) {
    }

    /**
     * @throws TripNotPublishableException when the trip breaks a rule (the message is meant for the driver);
     *                                     nothing is saved in that case
     */
    public function publish(Trip $trip, User $driver): Trip
    {
        $this->assertVehicleBelongsTo($trip, $driver);
        $this->assertHasEnoughStops($trip);
        $this->assertSeatsFitInVehicle($trip);
        $this->assertFirstDepartureIsInTheFuture($trip);
        $this->assertStopsAreConsistent($trip);
        $this->assertLegsArePriced($trip);

        $this->finalize($trip, $driver);
        $this->routeEnricher->enrich($trip); // best effort: never blocks publishing

        $this->em->persist($trip);
        $this->em->flush();

        return $trip;
    }

    private function assertVehicleBelongsTo(Trip $trip, User $driver): void
    {
        $owner = $trip->getVehicle()?->getOwner();
        $sameUser = $owner === $driver
            || (null !== $owner?->getId() && $owner->getId() === $driver->getId());
        if (!$sameUser) {
            throw new TripNotPublishableException('Ce véhicule n\'est pas le vôtre.');
        }
    }

    private function assertHasEnoughStops(Trip $trip): void
    {
        if ($trip->getStops()->count() < 2) {
            throw new TripNotPublishableException('Un trajet a au moins un départ et une arrivée.');
        }
    }

    private function assertSeatsFitInVehicle(Trip $trip): void
    {
        $seats = $trip->getSeatsOffered();
        $max = $trip->getVehicle()->getPassengerSeats();
        if ($seats < 1) {
            throw new TripNotPublishableException('Vous devez proposer au moins une place.');
        }
        if ($seats > $max) {
            throw new TripNotPublishableException(
                1 === $max
                    ? 'Votre véhicule n\'a qu\'une place passager.'
                    : sprintf('Votre véhicule n\'a que %d places passagers.', $max)
            );
        }
    }

    private function assertFirstDepartureIsInTheFuture(Trip $trip): void
    {
        if ($trip->getFirstStop()->getDepartureAt() <= $this->clock->now()) {
            throw new TripNotPublishableException('Le départ doit être dans le futur.');
        }
    }

    /** Each stop must leave after the previous one and be in a different city. */
    private function assertStopsAreConsistent(Trip $trip): void
    {
        $previous = null;
        foreach ($trip->getStops() as $stop) {
            if (null !== $previous) {
                if ($stop->getDepartureAt() <= $previous->getDepartureAt()) {
                    throw new TripNotPublishableException('Les horaires des arrêts doivent être strictement croissants.');
                }
                if ($this->sameCity($stop->getCity(), $previous->getCity())) {
                    throw new TripNotPublishableException('Deux arrêts consécutifs ne peuvent pas être dans la même ville.');
                }
            }
            $previous = $stop;
        }
    }

    private function sameCity(?City $a, ?City $b): bool
    {
        return $a === $b || (null !== $a?->getId() && $a->getId() === $b?->getId());
    }

    /** Every leg (all stops but the last) needs a price of 0 or more. */
    private function assertLegsArePriced(Trip $trip): void
    {
        $legStarts = $trip->getStops()->slice(0, -1);
        foreach ($legStarts as $stop) {
            $price = $stop->getLegPriceToNext();
            if (null === $price || $price < 0) {
                throw new TripNotPublishableException('Chaque tronçon doit avoir un prix positif ou nul.');
            }
        }
    }

    private function finalize(Trip $trip, User $driver): void
    {
        $position = 0;
        /** @var TripStop $stop */
        foreach ($trip->getStops() as $stop) {
            $stop->setPosition($position++);
        }
        $last = $trip->getLastStop();
        $last->setLegPriceToNext(null);
        $last->setLegDistanceKmToNext(null);
        $last->setLegDurationMinToNext(null);
        $last->setLegGeometry(null);

        $trip->setDriver($driver)->setStatus(TripStatus::Published);
    }
}
