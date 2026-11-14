<?php

namespace App\EventListener;

use App\Space;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Remembers in the session which space (passenger or driver) the signed-in user last visited. */
final class SpaceListener
{
    public function __construct(private readonly TokenStorageInterface $tokens)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || !\is_object($this->tokens->getToken()?->getUser())) {
            return;
        }
        $space = Space::ofRoute($request->attributes->get('_route'));
        if (null !== $space) {
            $request->getSession()->set(Space::SESSION_KEY, $space);
        }
    }
}
