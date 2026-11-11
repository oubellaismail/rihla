<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Exception\CancellationNotAllowedException;
use App\Exception\ReviewNotAllowedException;
use App\Form\ReviewType;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Security\BookingVoter;
use App\Service\CancellationPolicy;
use App\Service\CancellationService;
use App\Service\Geo\TripMapBuilder;
use App\Service\ReviewPolicy;
use App\Service\ReviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BookingController extends AbstractController
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    #[Route('/bookings', name: 'app_booking_index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function index(BookingRepository $bookings): Response
    {
        $now = $this->clock->now();
        $upcoming = [];
        $past = [];
        foreach ($bookings->findForPassenger($this->currentUser()) as $booking) {
            if (BookingStatus::Confirmed === $booking->getStatus() && $booking->getFromStop()->getDepartureAt() > $now) {
                $upcoming[] = $booking;
            } else {
                $past[] = $booking;
            }
        }
        // Next departure first for upcoming trips, most recent first for the others.
        usort($upcoming, static fn (Booking $a, Booking $b) => $a->getFromStop()->getDepartureAt() <=> $b->getFromStop()->getDepartureAt());

        return $this->render('booking/index.html.twig', ['upcoming' => $upcoming, 'past' => $past]);
    }

    #[Route('/bookings/{id<\d+>}', name: 'app_booking_show', methods: ['GET'])]
    public function show(Booking $booking, ReviewRepository $reviews, ReviewPolicy $policy, TripMapBuilder $mapBuilder): Response
    {
        $this->denyAccessUnlessGranted(BookingVoter::VIEW, $booking);
        $user = $this->currentUser();

        $canReview = $this->isGranted(BookingVoter::REVIEW, $booking)
            && $policy->canReview($booking, $user, $this->clock->now(), null !== $reviews->findOneBy(['booking' => $booking, 'author' => $user]));

        return $this->render('booking/show.html.twig', [
            'booking' => $booking,
            'is_passenger' => $booking->getPassenger() === $user,
            'can_cancel' => $this->isGranted(BookingVoter::CANCEL, $booking),
            'can_review' => $canReview,
            'map' => $mapBuilder->build($booking->getTrip(), $user),
        ]);
    }

    #[Route('/bookings/{id<\d+>}/cancel', name: 'app_booking_cancel', methods: ['GET', 'POST'])]
    public function cancel(Booking $booking, Request $request, CancellationPolicy $policy, CancellationService $service): Response
    {
        $this->denyAccessUnlessGranted(BookingVoter::CANCEL, $booking);
        $now = $this->clock->now();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cancel_booking_'.$booking->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Le formulaire a expiré. Veuillez réessayer.');

                return $this->redirectToRoute('app_booking_cancel', ['id' => $booking->getId()]);
            }
            try {
                $payment = $service->cancelByPassenger($booking, $this->currentUser());
                $this->addFlash('success', sprintf('Réservation annulée. %d MAD vous seront remboursés.', $payment->getRefundedAmount()));
            } catch (CancellationNotAllowedException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_booking_show', ['id' => $booking->getId()]);
        }

        return $this->render('booking/cancel.html.twig', [
            'booking' => $booking,
            'refund' => $policy->passengerRefundAmount($booking, $now),
            'percent' => $policy->passengerRefundPercent($booking, $now),
        ]);
    }

    #[Route('/bookings/{id<\d+>}/review', name: 'app_booking_review', methods: ['GET', 'POST'])]
    public function review(Booking $booking, Request $request, ReviewPolicy $policy, ReviewRepository $reviews, ReviewService $service): Response
    {
        $this->denyAccessUnlessGranted(BookingVoter::REVIEW, $booking);
        $user = $this->currentUser();

        $already = null !== $reviews->findOneBy(['booking' => $booking, 'author' => $user]);
        if (!$policy->canReview($booking, $user, $this->clock->now(), $already)) {
            $this->addFlash('error', $already ? 'Vous avez déjà laissé un avis pour ce trajet.' : 'Le délai pour laisser un avis est dépassé.');

            return $this->redirectToRoute('app_booking_show', ['id' => $booking->getId()]);
        }

        $target = $policy->targetFor($booking, $user);
        $form = $this->createForm(ReviewType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{rating: int|string, comment: ?string} $data */
            $data = $form->getData();
            try {
                $service->review($booking, $user, (int) $data['rating'], $data['comment'] ?? null);
                $this->addFlash('success', 'Merci, votre avis a été publié.');

                return $this->redirectToRoute('app_user_profile', ['id' => $target->getId()]);
            } catch (ReviewNotAllowedException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_booking_show', ['id' => $booking->getId()]);
            }
        }

        return $this->render('booking/review.html.twig', ['booking' => $booking, 'target' => $target, 'form' => $form]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
