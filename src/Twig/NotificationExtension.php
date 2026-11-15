<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** unread_notifications() for the header bell (one COUNT query) and time_ago() for the notices. */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ClockInterface $clock,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', $this->unread(...)),
            new TwigFunction('time_ago', $this->timeAgo(...)),
        ];
    }

    public function unread(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notifications->countUnread($user) : 0;
    }

    /** « Il y a 10 min », « Il y a 2 h », « Hier », else « 13 nov. » */
    public function timeAgo(\DateTimeInterface $at): string
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $at = \DateTimeImmutable::createFromInterface($at)->setTimezone($now->getTimezone());
        $minutes = max(0, intdiv($now->getTimestamp() - $at->getTimestamp(), 60));

        if ($minutes < 60) {
            return sprintf('Il y a %d min', max(1, $minutes));
        }
        if ($at->format('Y-m-d') === $now->format('Y-m-d')) {
            return sprintf('Il y a %d h', intdiv($minutes, 60));
        }
        if ($at->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            return 'Hier';
        }

        return (string) (new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $now->getTimezone(), \IntlDateFormatter::GREGORIAN, 'd MMM'))->format($at);
    }
}
