<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Review;
use App\Entity\Trip;
use App\Enum\LuggageSize;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tells drivers (in French) about bookings, cancellations and reviews, and rings the bell for new reviews.
 *
 * Always called after the change is committed.
 */
final class DriverNotifier
{
    public function __construct(
        private readonly UserNotifier $notices,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function notifyNewBooking(Booking $booking): void
    {
        [$subject, $body] = $this->newBookingText($booking);
        $this->send($booking->getTrip(), Notification::KIND_NEW_BOOKING, $subject, $body, $booking);
    }

    public function notifyPassengerCancelled(Booking $booking): void
    {
        [$subject, $body] = $this->passengerCancelledText($booking);
        $this->send($booking->getTrip(), Notification::KIND_PASSENGER_CANCELLED, $subject, $body, $booking);
    }

    /**
     * The words of the « new booking » notice and e-mail: [subject, body]. Public so the demo data can create the same
     * notice without sending anything.
     *
     * @return array{string, string}
     */
    public function newBookingText(Booking $booking): array
    {
        [$from, $to] = $this->route($booking);
        $seats = $booking->getSeats();
        $places = sprintf('%d place%s', $seats, $seats > 1 ? 's' : '');
        if ([] !== $booking->getSeatCodes()) {
            $places .= ' ('.implode(', ', $booking->getSeatCodes()).')';
        }
        $parts = [$places];
        if (array_sum($booking->getBags()) > 0) {
            $parts[] = LuggageSize::describeBags($booking->getBags());
        }
        $parts[] = $booking->getTotalPrice().' MAD';
        $parts[] = 'trajet du '.$this->tripDate($booking);

        return [sprintf('Nouvelle réservation : %s, %s → %s', $booking->getPassenger()->getPublicName(), $from, $to), implode(' · ', $parts)];
    }

    /** @return array{string, string} [subject, body] of the « booking cancelled by its passenger » notice */
    public function passengerCancelledText(Booking $booking): array
    {
        [$from, $to] = $this->route($booking);
        $seats = $booking->getSeats();

        return [
            sprintf('Réservation annulée : %s, %s → %s', $booking->getPassenger()->getPublicName(), $from, $to),
            sprintf('%d place%s libérée%s de %s à %s · trajet du %s', $seats, $seats > 1 ? 's' : '', $seats > 1 ? 's' : '', $from, $to, $this->tripDate($booking)),
        ];
    }

    /** @return array{string, string} [subject, body] of the « new review » notice */
    public function reviewText(Review $review): array
    {
        $comment = $review->getComment();

        return [
            sprintf('Nouvel avis de %s : %d/5', $review->getAuthor()->getPublicName(), $review->getRating()),
            null === $comment || '' === $comment ? 'Sans commentaire.' : '« '.$comment.' »',
        ];
    }

    public function notifyTripCancelledByAdmin(Trip $trip, int $passengers): void
    {
        $refunded = 0 === $passengers
            ? "Aucun passager n'avait réservé."
            : sprintf('%d passager%s remboursé%s intégralement', $passengers, $passengers > 1 ? 's' : '', $passengers > 1 ? 's' : '');
        $this->send($trip, Notification::KIND_TRIP_CANCELLED_BY_ADMIN, sprintf('Votre trajet %s → %s a été annulé par Rihla', $trip->getFirstStop()->getCity()->getName(), $trip->getLastStop()->getCity()->getName()), $refunded.'. Contactez-nous pour en savoir plus.');
    }

    /** A notice only, no e-mail: the bell of the reviewed user (driver or passenger). */
    public function notifyNewReview(Review $review): void
    {
        $target = $review->getTarget();
        $author = $review->getAuthor();
        if (null === $target || null === $author) {
            return;
        }
        [$subject, $body] = $this->reviewText($review);
        $this->notices->notify($target, Notification::KIND_REVIEW, $subject, $body);
    }

    private function send(Trip $trip, string $kind, string $subject, string $body, ?Booking $booking = null): void
    {
        $driver = $trip->getDriver();
        $this->notices->notify($driver, $kind, $subject, $body, $booking, $trip, 'driver_notice', [
            'body' => $body,
            'tripUrl' => $this->urls->generate('app_driver_trip_show', ['id' => $trip->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    /** @return array{string, string} */
    private function route(Booking $booking): array
    {
        return [$booking->getFromStop()->getCity()->getName(), $booking->getToStop()->getCity()->getName()];
    }

    private function tripDate(Booking $booking): string
    {
        return UserNotifier::date($booking->getTrip()->getFirstStop()->getDepartureAt());
    }
}
