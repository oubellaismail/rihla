<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Skeleton: routes are fixed by the design; actions are filled in by the implementation. */
final class BookingController extends AbstractController
{
    #[Route('/bookings', name: 'app_booking_index', methods: ['GET'])]
    public function index(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/bookings/{id<\d+>}', name: 'app_booking_show', methods: ['GET'])]
    public function show(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/bookings/{id<\d+>}/cancel', name: 'app_booking_cancel', methods: ['GET', 'POST'])]
    public function cancel(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/bookings/{id<\d+>}/review', name: 'app_booking_review', methods: ['GET', 'POST'])]
    public function review(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

}
