<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Skeleton: routes are fixed by the design; actions are filled in by the implementation. */
final class DriverTripController extends AbstractController
{
    #[Route('/driver/trips', name: 'app_driver_trips', methods: ['GET'])]
    public function index(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/driver/trips/{id<\d+>}', name: 'app_driver_trip_show', methods: ['GET'])]
    public function show(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/driver/trips/{id<\d+>}/cancel', name: 'app_driver_trip_cancel', methods: ['POST'])]
    public function cancel(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

}
