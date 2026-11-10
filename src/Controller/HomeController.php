<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Repository\BookingRepository;
use App\Repository\CityRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use App\Service\PriceCalculator;
use App\Service\SeatAvailabilityCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    private const UPCOMING_TRIPS = 4;
    private const REVIEWS = 3;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        ClockInterface $clock,
        CityRepository $cities,
        TripRepository $trips,
        BookingRepository $bookings,
        ReviewRepository $reviews,
        SeatAvailabilityCalculator $seatAvailability,
        PriceCalculator $priceCalculator,
    ): Response {
        $now = $clock->now();

        $cards = [];
        foreach ($trips->findUpcoming($now, self::UPCOMING_TRIPS) as $trip) {
            $legs = $this->legs($trip, $seatAvailability);
            $first = $trip->getFirstStop();
            $last = $trip->getLastStop();
            $cards[] = [
                'trip' => $trip,
                'legs' => $legs,
                'freeSeats' => [] === $legs ? 0 : min(array_column($legs, 'free')),
                'price' => $priceCalculator->portionPrice($trip, $first, $last, 1),
                'photo' => $this->photoFor($first->getCity()->getName(), $last->getCity()->getName()),
            ];
        }

        // The note about seats per leg is more telling on a trip with several legs.
        $spotlight = null;
        foreach ($cards as $card) {
            if (count($card['legs']) >= 2) {
                $spotlight = $card;
                break;
            }
        }

        $latestBooking = $bookings->findLatestConfirmed();

        return $this->render('home/index.html.twig', [
            'today' => $now->format('Y-m-d'),
            'cityNames' => $cities->findAllNames(),
            'cards' => $cards,
            'spotlight' => $spotlight,
            'latestBooking' => $latestBooking,
            'latestBookingAgo' => $latestBooking instanceof Booking ? $this->ago($latestBooking->getCreatedAt(), $now) : null,
            'reviews' => $reviews->findLatestWithComment(self::REVIEWS),
        ]);
    }

    /** @return list<array{from: string, to: string, free: int}> free seats on each leg of the trip */
    private function legs(Trip $trip, SeatAvailabilityCalculator $seatAvailability): array
    {
        $stops = $trip->getStops()->toArray();
        $taken = $seatAvailability->takenPerLeg(count($stops) - 1, $trip->getBookings());

        $legs = [];
        foreach ($taken as $i => $count) {
            $legs[] = [
                'from' => $stops[$i]->getCity()->getName(),
                'to' => $stops[$i + 1]->getCity()->getName(),
                'free' => max(0, $trip->getSeatsOffered() - $count),
            ];
        }

        return $legs;
    }

    private function photoFor(string $from, string $to): string
    {
        $cities = [mb_strtolower($from), mb_strtolower($to)];
        $has = static fn (string $name): bool => in_array($name, $cities, true);

        return match (true) {
            $has('essaouira') => 'images/route-essaouira-coast.jpg',
            $has('tanger') => 'images/route-tanger-corniche.jpg',
            $has('ouarzazate') => 'images/route-tizi-n-tichka.jpg',
            $has('agadir'), $has('marrakech') => 'images/route-agadir-marrakech-atlas.jpg',
            default => 'images/trip-windshield-road.jpg',
        };
    }

    private function ago(\DateTimeImmutable $when, \DateTimeImmutable $now): string
    {
        $minutes = max(0, intdiv($now->getTimestamp() - $when->getTimestamp(), 60));

        return match (true) {
            $minutes < 1 => 'à l’instant',
            $minutes < 60 => sprintf('il y a %d min', $minutes),
            $minutes < 1440 => sprintf('il y a %d h', intdiv($minutes, 60)),
            default => sprintf('il y a %d j', intdiv($minutes, 1440)),
        };
    }
}
