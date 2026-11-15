<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Trip;
use App\Entity\TripStop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tells passengers (in French) when the driver delays, shortens or cancels their trip.
 *
 * Always called after the change is committed. A failing mailer is logged and never breaks the caller.
 */
final class PassengerNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param iterable<Booking> $bookings confirmed bookings of the delayed trip
     * @param int               $minutes  this delay (the trip's stops are already moved)
     */
    public function notifyDelay(Trip $trip, iterable $bookings, int $minutes): void
    {
        $delay = TripChangeService::label($minutes);
        foreach ($bookings as $booking) {
            $from = $booking->getFromStop();
            $new = $from->getDepartureAt();
            $oldTime = $new->modify(sprintf('-%d minutes', $minutes))->format('H:i');
            $freeCancel = $trip->getDelayMinutes() >= CancellationPolicy::FREE_CANCEL_DELAY_MINUTES;
            $this->send($booking, Notification::KIND_DELAY, $freeCancel ? sprintf('%s au lieu de %s · vous pouvez annuler sans frais', $new->format('H:i'), $oldTime) : sprintf('%s au lieu de %s', $new->format('H:i'), $oldTime), sprintf('Votre trajet %s → %s part %s plus tard', $from->getCity()->getName(), $booking->getToStop()->getCity()->getName(), $delay), 'delay', [
                'driverFirstName' => $trip->getDriver()->getFirstName(),
                'delay' => $delay,
                'city' => $from->getCity()->getName(),
                'meetingPoint' => $from->getMeetingPoint(),
                'newTime' => $new->format('H:i'),
                'oldTime' => $oldTime,
                'date' => self::date($new),
                'freeCancel' => $freeCancel,
                'bookingUrl' => $this->urls->generate('app_booking_show', ['id' => $booking->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        }
    }

    /** @param iterable<Booking> $bookings bookings cancelled because $stop was removed */
    public function notifyStopRemoved(Trip $trip, TripStop $stop, iterable $bookings): void
    {
        $city = $stop->getCity()->getName();
        foreach ($bookings as $booking) {
            $amount = $this->amount($booking);
            $this->send($booking, Notification::KIND_STOP_REMOVED, sprintf('Remboursement intégral : %d MAD', $amount), sprintf("Réservation annulée : l'étape %s est retirée", $city), 'stop_removed', [
                'driver' => $trip->getDriver()->getFirstName(),
                'stopCity' => $city,
                'first' => $trip->getFirstStop()->getCity()->getName(),
                'last' => $trip->getLastStop()->getCity()->getName(),
                'date' => self::date($trip->getFirstStop()->getDepartureAt()),
                'amount' => $amount,
                'searchUrl' => $this->searchUrl($booking),
            ]);
        }
    }

    /** @param iterable<Booking> $bookings bookings cancelled with the trip */
    public function notifyTripCancelled(Trip $trip, iterable $bookings): void
    {
        $first = $trip->getFirstStop()->getCity()->getName();
        $last = $trip->getLastStop()->getCity()->getName();
        $date = self::date($trip->getFirstStop()->getDepartureAt());
        foreach ($bookings as $booking) {
            $amount = $this->amount($booking);
            $this->send($booking, Notification::KIND_TRIP_CANCELLED, sprintf('Remboursement intégral : %d MAD', $amount), sprintf('Trajet annulé : %s → %s, %s', $first, $last, $date), 'trip_cancelled', [
                'driver' => $trip->getDriver()->getFirstName(),
                'first' => $first,
                'last' => $last,
                'from' => $booking->getFromStop()->getCity()->getName(),
                'to' => $booking->getToStop()->getCity()->getName(),
                'amount' => $amount,
                'searchUrl' => $this->searchUrl($booking),
            ]);
        }
    }

    /** « jeudi 19 novembre » */
    private static function date(\DateTimeInterface $at): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $at->getTimezone(), \IntlDateFormatter::GREGORIAN, 'EEEE d MMMM');

        return (string) $formatter->format($at);
    }

    private function amount(Booking $booking): int
    {
        return $booking->getPayment()?->getRefundedAmount() ?? $booking->getTotalPrice();
    }

    private function searchUrl(Booking $booking): string
    {
        return $this->urls->generate('app_trip_search', [
            'from' => $booking->getFromStop()->getCity()->getName(),
            'to' => $booking->getToStop()->getCity()->getName(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** @param array<string, mixed> $context */
    private function send(Booking $booking, string $kind, string $body, string $subject, string $template, array $context): void
    {
        $passenger = $booking->getPassenger();
        // The in-app notice is saved first: a failing mailer must never lose it.
        $this->em->persist(new Notification($passenger, $booking, $kind, $subject, $body, \DateTimeImmutable::createFromInterface($this->clock->now())));
        $this->em->flush();
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address('noreply@rihla.ma', 'Rihla'))
                ->to(new Address((string) $passenger->getEmail(), (string) $passenger->getFullName()))
                ->subject($subject)
                ->htmlTemplate('email/'.$template.'.html.twig')
                ->textTemplate('email/'.$template.'.txt.twig')
                ->context($context + ['subject' => $subject, 'firstName' => $passenger->getFirstName()]));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Passenger e-mail not sent: '.$e->getMessage(), ['booking' => $booking->getId(), 'template' => $template]);
        }
    }
}
