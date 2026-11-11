<?php

namespace App\Service\Geo;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Model\GeoPoint;

/**
 * Fills the road distance, driving time and line of every leg of a trip (from OSRM), once, at publication,
 * so trip pages never call OSRM. Best effort: a leg that cannot be computed is left empty.
 * Does not flush.
 */
final class RouteEnricher
{
    public function __construct(private readonly RouteProvider $routes)
    {
    }

    public function enrich(Trip $trip): void
    {
        $previous = null;
        foreach ($trip->getStops() as $stop) {
            if (null !== $previous && !$this->fillLeg($previous, $stop)) {
                return; // OSRM failed: do not wait for it again on every remaining leg
            }
            $previous = $stop;
        }
    }

    /** @return bool false when OSRM failed (a leg skipped for missing coordinates is not a failure) */
    private function fillLeg(TripStop $from, TripStop $to): bool
    {
        $start = $this->pointOf($from);
        $end = $this->pointOf($to);
        if (null === $start || null === $end) {
            return true;
        }

        $leg = $this->routes->leg($start, $end);
        if (null === $leg) {
            return false;
        }

        $from->setLegDistanceKmToNext($leg->distanceKm)
            ->setLegDurationMinToNext($leg->durationMin)
            ->setLegGeometry($leg->geometry);

        return true;
    }

    /** The exact meeting point when the driver placed it, else the centre of the city. */
    private function pointOf(TripStop $stop): ?GeoPoint
    {
        if (null !== $stop->getMeetingLatitude() && null !== $stop->getMeetingLongitude()) {
            return new GeoPoint($stop->getMeetingLatitude(), $stop->getMeetingLongitude());
        }
        $city = $stop->getCity();
        if (null !== $city?->getLatitude() && null !== $city->getLongitude()) {
            return new GeoPoint($city->getLatitude(), $city->getLongitude());
        }

        return null;
    }
}
