<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\SeatState;
use App\Enum\TripStatus;
use App\Model\Seat;
use App\Exception\BookingNotAllowedException;
use App\Exception\NotEnoughSeatsException;
use App\Exception\SeatTakenException;
use App\Repository\CityRepository;
use App\Repository\ReviewRepository;
use App\Service\BookingService;
use App\Service\Geo\TripMapBuilder;
use App\Service\PriceCalculator;
use App\Service\Seat\SeatLayout;
use App\Service\Seat\SeatMap;
use App\Service\SeatAvailabilityCalculator;
use App\Service\TripSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TripController extends AbstractController
{
    private const MAX_SEATS = 4;

    #[Route('/trips', name: 'app_trip_search', methods: ['GET'])]
    public function search(
        Request $request,
        ClockInterface $clock,
        CityRepository $cities,
        TripSearch $tripSearch,
        ReviewRepository $reviews,
    ): Response {
        $today = $clock->now()->setTime(0, 0);
        $fromName = trim((string) $request->query->get('from', ''));
        $toName = trim((string) $request->query->get('to', ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date', '')) ?: $today;
        $seats = max(1, min(self::MAX_SEATS, $request->query->getInt('seats', 1)));

        $searched = '' !== $fromName && '' !== $toName;
        $unknownCity = null;
        $results = [];

        if ($searched) {
            $from = $cities->findOneByNameInsensitive($fromName);
            $to = $cities->findOneByNameInsensitive($toName);
            if (null === $from || null === $to) {
                $unknownCity = null === $from ? $fromName : $toName;
            } else {
                foreach ($tripSearch->search($from, $to, $date, $seats) as $match) {
                    $results[] = ['match' => $match, 'rating' => $reviews->summaryFor($match->trip->getDriver())];
                }
            }
        }

        return $this->render('trip/search.html.twig', [
            'from' => $fromName,
            'to' => $toName,
            'date' => $date,
            'seats' => $seats,
            'searched' => $searched,
            'unknownCity' => $unknownCity,
            'results' => $results,
            'cityNames' => $cities->findAllNames(),
        ]);
    }

    #[Route('/trips/{id<\d+>}', name: 'app_trip_show', methods: ['GET'])]
    public function show(
        Trip $trip,
        Request $request,
        ClockInterface $clock,
        SeatAvailabilityCalculator $seatAvailability,
        PriceCalculator $priceCalculator,
        ReviewRepository $reviews,
        TripMapBuilder $mapBuilder,
    ): Response {
        $stops = $trip->getStops()->toArray();
        $lastPosition = $trip->getLastStop()->getPosition();

        $fromPosition = $request->query->getInt('from', $trip->getFirstStop()->getPosition());
        $toPosition = $request->query->getInt('to', $lastPosition);
        $from = $this->stopAt($trip, $fromPosition);
        $to = $this->stopAt($trip, $toPosition);
        if (null === $from || null === $to || $from->getPosition() >= $to->getPosition()) {
            $from = $trip->getFirstStop();
            $to = $trip->getLastStop();
        }

        $taken = $seatAvailability->takenPerLeg(count($stops) - 1, $trip->getBookings());
        $legSeats = [];
        foreach ($taken as $i => $count) {
            $legSeats[$stops[$i]->getPosition()] = max(0, $trip->getSeatsOffered() - $count);
        }
        $portionLegs = array_filter(
            $legSeats,
            static fn (int $free, int $position): bool => $position >= $from->getPosition() && $position < $to->getPosition(),
            ARRAY_FILTER_USE_BOTH,
        );
        $freeSeats = [] === $portionLegs ? 0 : min($portionLegs);

        $now = $clock->now();
        $departed = $trip->getFirstStop()->getDepartureAt() <= $now;
        $driver = $trip->getDriver();

        return $this->render('trip/show.html.twig', [
            'trip' => $trip,
            'from' => $from,
            'to' => $to,
            'legSeats' => $legSeats,
            'freeSeats' => $freeSeats,
            'maxSeats' => min(self::MAX_SEATS, $freeSeats),
            'price' => $priceCalculator->portionPrice($trip, $from, $to, 1),
            'tripPrice' => $priceCalculator->portionPrice($trip, $trip->getFirstStop(), $trip->getLastStop(), 1),
            'isDriver' => $this->getUser() === $driver,
            'isOpen' => TripStatus::Published === $trip->getStatus() && !$departed,
            'departed' => $departed,
            'rating' => $reviews->summaryFor($driver),
            'map' => $mapBuilder->build($trip, $this->getUser() instanceof User ? $this->getUser() : null, $from, $to),
        ]);
    }

    #[Route('/trips/{id<\d+>}/seats', name: 'app_trip_seats', methods: ['GET'])]
    public function seats(
        Trip $trip,
        Request $request,
        ClockInterface $clock,
        SeatMap $seatMap,
        SeatLayout $layout,
        PriceCalculator $priceCalculator,
    ): Response {
        $fromPosition = $request->query->getInt('from', -1);
        $toPosition = $request->query->getInt('to', -1);
        $seats = $request->query->getInt('seats', 0);
        $from = $this->stopAt($trip, $fromPosition);
        $to = $this->stopAt($trip, $toPosition);

        if (null === $from || null === $to || $from->getPosition() >= $to->getPosition() || $seats < 1 || $seats > self::MAX_SEATS) {
            $this->addFlash('error', 'Cette réservation est invalide. Choisissez votre portion et le nombre de places.');

            return $this->redirectToRoute('app_trip_show', ['id' => $trip->getId()]);
        }

        $back = $this->redirectToRoute('app_trip_show', ['id' => $trip->getId(), 'from' => $fromPosition, 'to' => $toPosition]);
        $open = TripStatus::Published === $trip->getStatus() && $trip->getFirstStop()->getDepartureAt() > $clock->now();
        if (!$open || $this->getUser() === $trip->getDriver()) {
            $this->addFlash('error', !$open ? 'Ce trajet n’est plus ouvert à la réservation.' : 'C’est votre trajet : vous ne pouvez pas y réserver une place.');

            return $back;
        }

        $states = $seatMap->states($trip, $fromPosition, $toPosition);
        $openCount = count(array_filter($states, static fn (SeatState $state): bool => $state->isOpen()));
        if ($openCount < $seats) {
            $this->addFlash('error', (new NotEnoughSeatsException($seats, $openCount))->getMessage());

            return $back;
        }

        $passengerSeats = $trip->getVehicle()->getPassengerSeats();

        return $this->render('trip/seats.html.twig', [
            'trip' => $trip,
            'from' => $from,
            'to' => $to,
            'seatCount' => $seats,
            'states' => $states,
            'plan' => $layout->seats($passengerSeats),
            'kind' => $layout->kind($passengerSeats),
            'offered' => count($layout->offeredCodes($trip)),
            'total' => $priceCalculator->portionPrice($trip, $from, $to, $seats),
        ]);
    }

    #[Route('/trips/{id<\d+>}/book', name: 'app_trip_book', methods: ['POST'])]
    public function book(Trip $trip, Request $request, BookingService $bookingService, SeatMap $seatMap, SeatLayout $layout, PriceCalculator $priceCalculator): Response
    {
        $fromPosition = $request->request->getInt('from', -1);
        $toPosition = $request->request->getInt('to', -1);
        $back = $this->redirectToRoute('app_trip_show', ['id' => $trip->getId(), 'from' => $fromPosition, 'to' => $toPosition]);

        if (!$this->isCsrfTokenValid('book-trip-'.$trip->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Votre session a expiré. Veuillez réessayer.');

            return $back;
        }

        $from = $this->stopAt($trip, $fromPosition);
        $to = $this->stopAt($trip, $toPosition);
        $seats = $request->request->getInt('seats', 0);
        if (null === $from || null === $to || $seats < 1) {
            $this->addFlash('error', 'Cette réservation est invalide. Choisissez votre portion et le nombre de places.');

            return $back;
        }

        $seatCodes = $request->request->has('auto')
            ? []
            : array_values(array_filter($request->request->all('seatCodes'), 'is_string'));

        /** @var User $user */
        $user = $this->getUser();

        try {
            $booking = $bookingService->book($trip, $from, $to, $seats, $user, null, $seatCodes);
        } catch (SeatTakenException $e) {
            $suggestion = $seatMap->autoPick($trip, $fromPosition, $toPosition, $seats);
            $suggested = count($suggestion) === $seats
                ? array_values(array_filter($layout->seats($trip->getVehicle()->getPassengerSeats()), static fn (Seat $seat): bool => in_array($seat->code, $suggestion, true)))
                : [];

            return $this->render('trip/seat_taken.html.twig', [
                'trip' => $trip,
                'from' => $from,
                'to' => $to,
                'seatCount' => $seats,
                'headline' => rtrim($e->getMessage(), '.'),
                'suggested' => $suggested,
                'total' => $priceCalculator->portionPrice($trip, $from, $to, $seats),
            ], new Response(status: Response::HTTP_CONFLICT));
        } catch (NotEnoughSeatsException|BookingNotAllowedException $e) {
            $this->addFlash('error', $e->getMessage());

            if ([] !== $seatCodes) {
                return $this->redirectToRoute('app_trip_seats', ['id' => $trip->getId(), 'from' => $fromPosition, 'to' => $toPosition, 'seats' => $seats]);
            }

            return $back;
        }

        $this->addFlash('success', 'Réservation confirmée.');

        return $this->redirectToRoute('app_booking_show', ['id' => $booking->getId()]);
    }

    private function stopAt(Trip $trip, int $position): ?TripStop
    {
        foreach ($trip->getStops() as $stop) {
            if ($stop->getPosition() === $position) {
                return $stop;
            }
        }

        return null;
    }
}
