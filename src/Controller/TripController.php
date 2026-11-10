<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Skeleton: routes are fixed by the design; actions are filled in by the implementation. */
final class TripController extends AbstractController
{
    #[Route('/trips', name: 'app_trip_search', methods: ['GET'])]
    public function search(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/trips/{id<\d+>}', name: 'app_trip_show', methods: ['GET'])]
    public function show(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/trips/{id<\d+>}/book', name: 'app_trip_book', methods: ['POST'])]
    public function book(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

}
