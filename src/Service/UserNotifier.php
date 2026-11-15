<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Trip;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The shared plumbing of the passenger and driver notifiers: saves the in-app notice, then sends the e-mail.
 *
 * Always called after the change is committed. A failing mailer is logged and never breaks the caller.
 */
final class UserNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /** « jeudi 19 novembre » */
    public static function date(\DateTimeInterface $at): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $at->getTimezone(), \IntlDateFormatter::GREGORIAN, 'EEEE d MMMM');

        return (string) $formatter->format($at);
    }

    /**
     * @param string|null          $template e-mail template name under email/ (null: in-app notice only)
     * @param array<string, mixed> $context
     */
    public function notify(User $user, string $kind, string $subject, string $body, ?Booking $booking = null, ?Trip $trip = null, ?string $template = null, array $context = []): void
    {
        // The in-app notice is saved first: a failing mailer must never lose it.
        $this->em->persist(new Notification($user, $booking, $kind, $subject, $body, \DateTimeImmutable::createFromInterface($this->clock->now()), $trip));
        $this->em->flush();
        if (null === $template) {
            return;
        }
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address('noreply@rihla.ma', 'Rihla'))
                ->to(new Address((string) $user->getEmail(), (string) $user->getFullName()))
                ->subject($subject)
                ->htmlTemplate('email/'.$template.'.html.twig')
                ->textTemplate('email/'.$template.'.txt.twig')
                ->context($context + ['subject' => $subject, 'firstName' => $user->getFirstName()]));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('E-mail not sent: '.$e->getMessage(), ['user' => $user->getId(), 'template' => $template]);
        }
    }
}
