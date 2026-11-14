<?php

namespace App\Twig;

use App\Space;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** current_space(): 'passenger' or 'driver', for the header switch and menu. */
final class SpaceExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('current_space', $this->currentSpace(...))];
    }

    public function currentSpace(): string
    {
        $request = $this->requests->getMainRequest();
        $space = $request && $request->hasSession() ? $request->getSession()->get(Space::SESSION_KEY) : null;

        return Space::DRIVER === $space ? Space::DRIVER : Space::PASSENGER;
    }
}
