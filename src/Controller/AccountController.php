<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Skeleton: routes are fixed by the design; actions are filled in by the implementation. */
final class AccountController extends AbstractController
{
    #[Route('/account', name: 'app_account', methods: ['GET', 'POST'])]
    public function account(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

    #[Route('/account/vehicles', name: 'app_vehicles', methods: ['GET', 'POST'])]
    public function vehicles(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

}
