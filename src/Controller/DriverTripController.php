<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\CancellationNotAllowedException;
use App\Repository\TripRepository;
use App\Security\TripVoter;
use App\Service\CancellationService;
use App\Service\Geo\TripMapBuilder;
use App\Service\SeatAvailabilityCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DriverTripController extends AbstractController
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    #[Route('/driver/trips', name: 'app_driver_trips', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function index(TripRepository $trips, SeatAvailabilityCalculator $calculator): Response
    {
        $now = $this->clock->now();
        $upcoming = [];
        $past = [];
        foreach ($trips->findForDriver($this->currentUser()) as $trip) {
            $stops = $trip->getStops()->toArray();
            $taken = $calculator->occupiedPerLeg(max(0, \count($stops) - 1), $trip->getBookings());
            $carried = 0;
            foreach ($trip->getBookings() as $booking) {
                if (BookingStatus::Completed === $booking->getStatus()) {
                    $carried += $booking->getSeats();
                }
            }
            $item = ['trip' => $trip, 'stops' => \count($stops), 'taken' => [] === $taken ? 0 : max($taken), 'carried' => $carried];
            if (TripStatus::Published === $trip->getStatus() && $trip->getFirstStop()->getDepartureAt() > $now) {
                $upcoming[] = $item;
            } else {
                $past[] = $item;
            }
        }
        usort($upcoming, static fn (array $a, array $b) => $a['trip']->getFirstStop()->getDepartureAt() <=> $b['trip']->getFirstStop()->getDepartureAt());
        usort($past, static fn (array $a, array $b) => $b['trip']->getFirstStop()->getDepartureAt() <=> $a['trip']->getFirstStop()->getDepartureAt());

        return $this->render('driver/index.html.twig', ['upcoming' => $upcoming, 'past' => $past]);
    }

    #[Route('/driver/trips/{id<\d+>}', name: 'app_driver_trip_show', methods: ['GET'])]
    public function show(Trip $trip, SeatAvailabilityCalculator $calculator, TripMapBuilder $mapBuilder): Response
    {
        $this->denyAccessUnlessGranted(TripVoter::VIEW_PASSENGERS, $trip);

        $stops = $trip->getStops()->toArray();
        $taken = $calculator->occupiedPerLeg(max(0, \count($stops) - 1), $trip->getBookings());

        $boarding = [];
        $cancelled = [];
        foreach ($trip->getBookings() as $booking) {
            if ($booking->getStatus()->isCancelled()) {
                $cancelled[] = $booking;
            } elseif (\in_array($booking->getStatus(), [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
                $boarding[$booking->getFromStop()->getPosition()][] = $booking;
            }
        }

        $canCancel = $this->isGranted(TripVoter::CANCEL, $trip) && $this->clock->now() < $trip->getFirstStop()->getDepartureAt();

        return $this->render('driver/show.html.twig', [
            'trip' => $trip,
            'stops' => $stops,
            'taken' => $taken,
            'boarding' => $boarding,
            'cancelled' => $cancelled,
            'can_cancel' => $canCancel,
            'map' => $mapBuilder->build($trip, $this->currentUser()),
        ]);
    }

    #[Route('/driver/trips/{id<\d+>}/cancel', name: 'app_driver_trip_cancel', methods: ['POST'])]
    public function cancel(Trip $trip, Request $request, CancellationService $service): Response
    {
        $this->denyAccessUnlessGranted(TripVoter::CANCEL, $trip);

        if (!$this->isCsrfTokenValid('cancel_trip_'.$trip->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré. Veuillez réessayer.');

            return $this->redirectToRoute('app_driver_trip_show', ['id' => $trip->getId()]);
        }

        try {
            $count = $service->cancelTripByDriver($trip, $this->currentUser());
            $this->addFlash('success', sprintf(
                'Trajet annulé. %d %s.',
                $count,
                $count > 1 ? 'passagers remboursés intégralement' : 'passager remboursé intégralement',
            ));
        } catch (CancellationNotAllowedException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_driver_trip_show', ['id' => $trip->getId()]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
