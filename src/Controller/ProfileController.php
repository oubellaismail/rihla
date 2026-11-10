<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/users/{id<\d+>}', name: 'app_user_profile', methods: ['GET'])]
    public function show(User $user, ReviewRepository $reviews, TripRepository $trips, ClockInterface $clock): Response
    {
        return $this->render('profile/show.html.twig', [
            'member' => $user,
            'summary' => $reviews->summaryFor($user),
            'reviews' => $reviews->receivedBy($user),
            'completed' => $trips->countCompletedBy($user),
            'cancelled' => $trips->countCancelledBy($user),
            'upcoming' => $trips->upcomingPublishedBy($user, $clock->now()),
        ]);
    }
}
