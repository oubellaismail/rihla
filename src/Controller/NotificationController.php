<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NotificationController extends AbstractController
{
    #[Route('/notifications', name: 'app_notifications', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function index(NotificationRepository $notifications, ClockInterface $clock): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Render first (unread notices are shown as new this once), then mark them all as read.
        $response = $this->render('notification/index.html.twig', ['notifications' => $notifications->forUser($user)]);
        $notifications->markAllRead($user, \DateTimeImmutable::createFromInterface($clock->now()));

        return $response;
    }
}
