<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\TripChangeNotAllowedException;
use App\Service\Geo\RouteEnricher;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Changes a published trip that has not started: pushing every departure back, or removing an intermediate stop.
 * Both lock the trip row like BookingService::book(), so nobody books while the trip is being rewritten.
 */
final class TripChangeService
{
    /** Delays the driver may choose, in minutes. A trip never leaves earlier than announced. */
    public const DELAYS = [15, 30, 45, 60, 120, 180];

    /** A trip is never delayed by more than 6 h in total: beyond, the driver cancels it. */
    public const MAX_TOTAL_DELAY_MINUTES = 360;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly RouteEnricher $routeEnricher,
        private readonly PassengerNotifier $notifier,
    ) {
    }

    /** « 45 min », « 1 h », « 1 h 15 »: how a delay is written to users. */
    public static function label(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h'.(0 === $minutes % 60 ? '' : ' '.($minutes % 60));
    }

    /** Whether the trip can still take a delay of $minutes without passing the 6 h cap. */
    public function canDelayBy(Trip $trip, int $minutes): bool
    {
        return $trip->getDelayMinutes() + $minutes <= self::MAX_TOTAL_DELAY_MINUTES;
    }

    /** Whether the trip can still be changed (published, first departure ahead). */
    public function isChangeable(Trip $trip): bool
    {
        return TripStatus::Published === $trip->getStatus()
            && null !== $trip->getFirstStop()
            && $this->clock->now() < $trip->getFirstStop()->getDepartureAt();
    }

    /** Whether $stop is a stop between the departure and the arrival of $trip (the only removable ones). */
    public function isIntermediate(Trip $trip, TripStop $stop): bool
    {
        $stops = $trip->getStops();

        return $stop->getTrip() === $trip && !$stop->isRemoved()
            && $stop->getPosition() > 0 && $stop->getPosition() < $stops->count() - 1;
    }

    /**
     * Moves every stop of the trip $minutes later and adds up the trip's delay.
     *
     * @throws TripChangeNotAllowedException when the delay is not offered or the trip cannot change any more
     */
    public function delay(Trip $trip, int $minutes): void
    {
        if (!\in_array($minutes, self::DELAYS, true)) {
            throw new TripChangeNotAllowedException('Ce retard n\'est pas proposé.');
        }

        $this->em->wrapInTransaction(function () use ($trip, $minutes): void {
            $this->lockAndCheck($trip);
            if (!$this->canDelayBy($trip, $minutes)) {
                throw new TripChangeNotAllowedException('Au-delà de 6 h de retard, annulez le trajet.');
            }

            foreach ($trip->getStops() as $stop) {
                $stop->setDepartureAt($stop->getDepartureAt()->modify(sprintf('+%d minutes', $minutes)));
            }
            $trip->addDelay($minutes);
            $this->em->flush();
        });

        // After the commit: a mailer failure must not undo the delay.
        $this->notifier->notifyDelay($trip, $trip->getBookings()->filter(
            static fn (Booking $b): bool => BookingStatus::Confirmed === $b->getStatus(),
        ), $minutes);
    }

    /**
     * Removes an intermediate stop. Confirmed bookings boarding or leaving there are cancelled by the driver and
     * fully refunded; the others keep their seats and price, the two legs around the stop becoming one.
     *
     * The stop row is kept (see TripStop) so cancelled bookings keep their foreign keys and their history.
     *
     * @return int the number of bookings cancelled
     *
     * @throws TripChangeNotAllowedException when the trip cannot change any more or the stop is not intermediate
     */
    public function removeStop(Trip $trip, TripStop $stop): int
    {
        $cancelled = $this->em->wrapInTransaction(function () use ($trip, $stop): array {
            $this->lockAndCheck($trip);
            if (!$this->isIntermediate($trip, $stop)) {
                throw new TripChangeNotAllowedException('Seules les étapes intermédiaires peuvent être retirées.');
            }

            $now = $this->clock->now();
            $reason = sprintf('Le conducteur a retiré l\'étape %s.', $stop->getCity()->getName());
            $cancelled = [];
            foreach ($trip->getBookings() as $booking) {
                if (BookingStatus::Confirmed !== $booking->getStatus() || !$this->touches($booking, $stop)) {
                    continue;
                }
                $booking->setStatus(BookingStatus::CancelledByDriver)->setCancelledAt($now)->setCancellationReason($reason);
                $booking->getPayment()?->refund($booking->getPayment()->getAmount());
                $cancelled[] = $booking;
            }

            // The previous stop now drives to the next one: its leg is the sum of the two.
            $previous = $trip->getStops()->get($stop->getPosition() - 1);
            $previous->setLegPriceToNext((int) $previous->getLegPriceToNext() + (int) $stop->getLegPriceToNext());
            $bothKnown = null !== $previous->getLegDistanceKmToNext() && null !== $stop->getLegDistanceKmToNext()
                && null !== $previous->getLegDurationMinToNext() && null !== $stop->getLegDurationMinToNext();
            $previous->setLegDistanceKmToNext($bothKnown ? $previous->getLegDistanceKmToNext() + $stop->getLegDistanceKmToNext() : null)
                ->setLegDurationMinToNext($bothKnown ? $previous->getLegDurationMinToNext() + $stop->getLegDurationMinToNext() : null)
                ->setLegGeometry(null);

            // Two flushes: the removed stop must free its position before the others take the numbers below it
            // (unique constraint on trip + position).
            $stop->markRemoved($now);
            $this->em->flush();
            foreach ($trip->getStops() as $i => $remaining) {
                $remaining->setPosition($i);
            }
            $this->em->flush();

            return $cancelled;
        });

        $this->notifier->notifyStopRemoved($trip, $stop, $cancelled);

        // Best effort and outside the lock: a slow or failing OSRM must not undo the removal.
        try {
            $this->routeEnricher->enrich($trip);
            $this->em->flush();
        } catch (\Throwable) {
        }

        return \count($cancelled);
    }

    /** Confirmed bookings boarding or leaving at $stop, then those passing through it. */
    public function affectedBookings(Trip $trip, TripStop $stop): array
    {
        $touched = [];
        $through = [];
        foreach ($trip->getBookings() as $booking) {
            if (BookingStatus::Confirmed !== $booking->getStatus()) {
                continue;
            }
            if ($this->touches($booking, $stop)) {
                $touched[] = $booking;
            } elseif ($booking->getFromStop()->getPosition() < $stop->getPosition() && $stop->getPosition() < $booking->getToStop()->getPosition()) {
                $through[] = $booking;
            }
        }

        return ['cancelled' => $touched, 'through' => $through];
    }

    private function touches(Booking $booking, TripStop $stop): bool
    {
        return $booking->getFromStop() === $stop || $booking->getToStop() === $stop;
    }

    private function lockAndCheck(Trip $trip): void
    {
        $this->em->lock($trip, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($trip); // the status may have changed while we waited for the lock
        if (!$this->isChangeable($trip)) {
            throw new TripChangeNotAllowedException('Ce trajet ne peut plus être modifié.');
        }
    }
}
