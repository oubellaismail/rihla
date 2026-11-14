<?php

namespace App\Service;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Exception\TripNotPublishableException;
use App\Model\GeoPoint;
use App\Service\Geo\MeetingPointRule;
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
        private readonly MeetingPointRule $meetingPointRule,
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
        $this->assertTrunkFitsInVehicle($trip);
        $this->assertFirstDepartureIsInTheFuture($trip);
        $this->assertStopsAreConsistent($trip);
        $this->assertLegsArePriced($trip);
        $this->assertMeetingPointsAreInTheirCity($trip);

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

    /** The trip freezes its own trunk volume: the vehicle's when left empty, never more than the vehicle's. */
    private function assertTrunkFitsInVehicle(Trip $trip): void
    {
        $max = $trip->getVehicle()->getTrunkLitres();
        $litres = $trip->getTrunkLitres();
        if ($litres < 0) {
            throw new TripNotPublishableException('Le volume du coffre ne peut pas être négatif.');
        }
        if ($litres > $max) {
            throw new TripNotPublishableException(sprintf('Le coffre de votre véhicule ne fait que %d L.', $max));
        }
        $trip->setTrunkLitres($litres);
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
        foreach ($trip->getStops() as $i => $stop) {
            $n = $i + 1;
            if (null !== $previous) {
                if ($stop->getDepartureAt() <= $previous->getDepartureAt()) {
                    throw new TripNotPublishableException(sprintf('Arrêt %d (%s) : l\'heure doit être après celle de l\'arrêt précédent.', $n, $stop->getCity()?->getName()));
                }
                if ($this->sameCity($stop->getCity(), $previous->getCity())) {
                    throw new TripNotPublishableException(sprintf('Arrêt %d (%s) : même ville que l\'arrêt précédent.', $n, $stop->getCity()?->getName()));
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
        $stops = $trip->getStops()->getValues();
        foreach (\array_slice($stops, 0, -1) as $i => $stop) {
            $price = $stop->getLegPriceToNext();
            $leg = sprintf('Tronçon %s → %s', $stop->getCity()?->getName(), $stops[$i + 1]->getCity()?->getName());
            if (null === $price) {
                throw new TripNotPublishableException($leg.' : indiquez un prix (0 si gratuit).');
            }
            if ($price < 0) {
                throw new TripNotPublishableException($leg.' : le prix ne peut pas être négatif.');
            }
        }
    }

    /** A meeting point placed on the map must be at most MeetingPointRule::MAX_KM from its city. */
    private function assertMeetingPointsAreInTheirCity(Trip $trip): void
    {
        foreach ($trip->getStops() as $stop) {
            $city = $stop->getCity();
            if (null === $city || !$city->isGeocoded()
                || null === $stop->getMeetingLatitude() || null === $stop->getMeetingLongitude()) {
                continue;
            }
            $meeting = new GeoPoint($stop->getMeetingLatitude(), $stop->getMeetingLongitude());
            $centre = new GeoPoint($city->getLatitude(), $city->getLongitude());
            if (!$this->meetingPointRule->isNear($meeting, $centre)) {
                throw new TripNotPublishableException(sprintf(
                    'Le point de rendez-vous choisi pour %s est à %d km de la ville. Choisissez un endroit à moins de %d km.',
                    $city->getName(),
                    (int) round($this->meetingPointRule->distanceKm($meeting, $centre)),
                    MeetingPointRule::MAX_KM,
                ));
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
