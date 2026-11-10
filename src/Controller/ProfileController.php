<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Skeleton: routes are fixed by the design; actions are filled in by the implementation. */
final class ProfileController extends AbstractController
{
    #[Route('/users/{id<\d+>}', name: 'app_user_profile', methods: ['GET'])]
    public function show(): Response
    {
        throw new \LogicException('Not implemented yet.');
    }

}
