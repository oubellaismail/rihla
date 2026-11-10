<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Skeleton: routes are fixed by the design; actions are filled in by the implementation. */
final class TripPublishController extends AbstractController
{
    #[Route('/trips/new', name: 'app_trip_new', methods: ['GET', 'POST'])]
    public function new(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

}
