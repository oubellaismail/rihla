<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Tells the user they are logged out; runs after the session was invalidated so the flash survives. */
final class LogoutFlashSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', -100]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $session = $event->getRequest()->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', 'Vous êtes déconnecté.');
        }
    }
}
