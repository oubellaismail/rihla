<?php

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps an admin inside the back-office: an admin manages the platform, they do not use it
 * as a passenger or a driver, so every main request outside /admin is redirected to /admin.
 */
final class AdminConfinementListener
{
    /** Paths an admin may still reach: the back-office, logout, and dev/static resources. */
    private const ALLOWED = '#^/(admin|logout|_error|_profiler|_wdt|assets)(/|$)#';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    // Priority below the security firewall (8) so the user is already known.
    #[AsEventListener(event: 'kernel.request', priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (preg_match(self::ALLOWED, $event->getRequest()->getPathInfo()) || !$this->security->isGranted('ROLE_ADMIN')) {
            return;
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('admin')));
    }
}
