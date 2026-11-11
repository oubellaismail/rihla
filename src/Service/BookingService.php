<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\PaymentStatus;
use App\Enum\TripStatus;
use App\Exception\BookingNotAllowedException;
use App\Exception\NotEnoughSeatsException;
use App\Exception\SeatTakenException;
use App\Service\Seat\SeatLayout;
use App\Service\Seat\SeatMap;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Clock\ClockInterface;

/**
 * Books seats on a portion of a trip without ever overbooking it.
 */
final class BookingService
{
    private const MIN_SEATS = 1;
    private const MAX_SEATS = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeatAvailabilityCalculator $seatAvailability,
        private readonly PriceCalculator $priceCalculator,
        private readonly ClockInterface $clock,
        private readonly SeatMap $seatMap,
        private readonly SeatLayout $seatLayout,
    ) {
    }

    /**
     * Books $seats seats from stop $from to stop $to and records the (simulated) payment.
     *
     * The trip row is locked for the whole transaction: concurrent bookings of the same trip
     * run one after the other, so each one counts the seats left after the previous one.
     *
     * @param callable|null $onLocked test seam: called once the lock is held, before seats are counted,
     *                                to let a test keep the lock for a while
     * @param list<string> $seatCodes seats chosen, one per place; empty to let Rihla pick them
     *
     * @throws BookingNotAllowedException when the request breaks a rule (the message is meant for the user)
     * @throws SeatTakenException         when a chosen seat is held by someone else on that portion
     * @throws NotEnoughSeatsException    when the portion has fewer free seats than requested
     */
    public function book(Trip $trip, TripStop $from, TripStop $to, int $seats, User $passenger, ?callable $onLocked = null, array $seatCodes = []): Booking
    {
        $this->assertRequestIsValid($trip, $from, $to, $seats, $passenger, $seatCodes);

        return $this->em->wrapInTransaction(function () use ($trip, $from, $to, $seats, $passenger, $onLocked, $seatCodes): Booking {
            $this->em->lock($trip, LockMode::PESSIMISTIC_WRITE);
            // The status may have changed while we waited for the lock.
            $this->em->refresh($trip);

            if (null !== $onLocked) {
                $onLocked();
            }

            $this->assertTripIsBookable($trip, $from);

            $bookings = $this->loadConfirmedBookings($trip);
            $seatCodes = $this->resolveSeats($trip, $from, $to, $seats, $seatCodes, $bookings);

            $totalPrice = $this->priceCalculator->portionPrice($trip, $from, $to, $seats);
            $booking = (new Booking())
                ->setTrip($trip)
                ->setPassenger($passenger)
                ->setFromStop($from)
                ->setToStop($to)
                ->setSeats($seats)
                ->setSeatCodes($seatCodes)
                ->setTotalPrice($totalPrice)
                ->setStatus(BookingStatus::Confirmed)
                ->setCreatedAt($this->clock->now());
            $payment = (new Payment())
                ->setAmount($totalPrice)
                ->setStatus(PaymentStatus::Paid)
                ->setPaidAt($this->clock->now());
            $booking->setPayment($payment);
            $trip->getBookings()->add($booking);

            $this->em->persist($booking);
            $this->em->persist($payment);

            return $booking;
        });
    }

    /** Checks that need no database access, so they run before taking the lock. */
    private function assertRequestIsValid(Trip $trip, TripStop $from, TripStop $to, int $seats, User $passenger, array $seatCodes): void
    {
        if ($seats < self::MIN_SEATS || $seats > self::MAX_SEATS) {
            throw new BookingNotAllowedException(sprintf('Vous pouvez réserver entre %d et %d places.', self::MIN_SEATS, self::MAX_SEATS));
        }
        if ($from->getTrip() !== $trip || $to->getTrip() !== $trip) {
            throw new BookingNotAllowedException('Les arrêts choisis ne font pas partie de ce trajet.');
        }
        if ($from->getPosition() >= $to->getPosition()) {
            throw new BookingNotAllowedException('L\'arrêt de descente doit venir après l\'arrêt de montée.');
        }
        if ($passenger === $trip->getDriver()) {
            throw new BookingNotAllowedException('Le conducteur ne peut pas réserver son propre trajet.');
        }
        if ([] !== $seatCodes && (count($seatCodes) !== $seats || count(array_unique($seatCodes)) !== count($seatCodes))) {
            throw new BookingNotAllowedException(1 === $seats ? 'Choisissez une place.' : sprintf('Choisissez %d places, une par voyageur.', $seats));
        }
    }

    /**
     * The seats of the new booking: Rihla's pick when none was chosen, otherwise the chosen ones
     * once checked against the seats offered and the fresh state of the portion.
     *
     * @param list<string>  $chosen
     * @param list<Booking> $bookings
     *
     * @return list<string>
     */
    private function resolveSeats(Trip $trip, TripStop $from, TripStop $to, int $seats, array $chosen, array $bookings): array
    {
        $fromPosition = $from->getPosition();
        $toPosition = $to->getPosition();

        if ([] === $chosen) {
            $this->assertSeatsLeft($trip, $from, $to, $seats, $bookings);
            $picked = $this->seatMap->autoPick($trip, $fromPosition, $toPosition, $seats, $bookings);
            if (count($picked) < $seats) {
                throw new NotEnoughSeatsException($seats, count($picked));
            }

            return $picked;
        }

        $offered = $this->seatLayout->offeredCodes($trip);
        $states = $this->seatMap->states($trip, $fromPosition, $toPosition, $bookings);
        // A seat that was just taken is reported first: it is what the passenger needs to know to choose again.
        $taken = array_values(array_filter($chosen, static fn (string $code) => in_array($code, $offered, true) && !$states[$code]->isOpen()));
        if ([] !== $taken) {
            throw new SeatTakenException($taken);
        }
        $this->assertSeatsLeft($trip, $from, $to, $seats, $bookings);
        foreach ($chosen as $code) {
            if (!in_array($code, $offered, true)) {
                throw new BookingNotAllowedException(sprintf("La place %s n'est pas proposée sur ce trajet.", $code));
            }
        }

        return $chosen;
    }

    /**
     * The per-leg seat count rule, unchanged.
     *
     * @param list<Booking> $bookings
     */
    private function assertSeatsLeft(Trip $trip, TripStop $from, TripStop $to, int $seats, array $bookings): void
    {
        $freeSeats = $this->seatAvailability->freeSeats($trip->getSeatsOffered(), $bookings, $from->getPosition(), $to->getPosition());
        if ($freeSeats < $seats) {
            throw new NotEnoughSeatsException($seats, $freeSeats);
        }
    }

    /** Checks that depend on the fresh state of the trip, so they run under the lock. */
    private function assertTripIsBookable(Trip $trip, TripStop $from): void
    {
        if (TripStatus::Published !== $trip->getStatus()) {
            throw new BookingNotAllowedException('Ce trajet n\'est plus ouvert à la réservation.');
        }
        // Only the boarding stop matters: a car already on the road can still pick up at later stops.
        if ($from->getDepartureAt() <= $this->clock->now()) {
            throw new BookingNotAllowedException('Le véhicule a déjà quitté cet arrêt.');
        }
    }

    /**
     * Reads the confirmed bookings from the database instead of $trip->getBookings():
     * the in-memory collection may predate bookings committed by other processes.
     * HINT_REFRESH also overwrites bookings already in the identity map with their current row.
     *
     * @return list<Booking>
     */
    private function loadConfirmedBookings(Trip $trip): array
    {
        return $this->em->createQuery(
            'SELECT b, f, t FROM '.Booking::class.' b JOIN b.fromStop f JOIN b.toStop t WHERE b.trip = :trip AND b.status = :status'
        )
            ->setParameter('trip', $trip)
            ->setParameter('status', BookingStatus::Confirmed)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }
}
