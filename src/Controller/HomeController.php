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

    /** Photos available for the route cards. */
    private const PHOTOS = [
        'images/route-essaouira-coast.jpg',
        'images/route-agadir-marrakech-atlas.jpg',
        'images/route-tizi-n-tichka.jpg',
        'images/route-tanger-corniche.jpg',
        'images/trip-windshield-road.jpg',
    ];

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
        $usedPhotos = [];
        foreach ($trips->findUpcoming($now, self::UPCOMING_TRIPS) as $trip) {
            $legs = $this->legs($trip, $seatAvailability);
            $first = $trip->getFirstStop();
            $last = $trip->getLastStop();
            $free = array_column($legs, 'free');
            $freePerLeg = [];
            foreach (array_values($trip->getStops()->toArray()) as $i => $stop) {
                if (isset($legs[$i])) {
                    $freePerLeg[$stop->getPosition()] = $legs[$i]['free'];
                }
            }
            $photo = $this->photoFor($first->getCity()->getName(), $last->getCity()->getName(), $usedPhotos);
            $usedPhotos[] = $photo;
            $cards[] = [
                'trip' => $trip,
                'legs' => $legs,
                'freeSeats' => [] === $legs ? 0 : min($free),
                'someLegFree' => [] !== array_filter($free, static fn (int $n): bool => $n > 0),
                'price' => $priceCalculator->cheapestLegPrice($trip, $freePerLeg),
                'photo' => $photo,
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

    /**
     * The photo matching the route, or the next unused one when it is already on another card.
     *
     * @param list<string> $used photos already shown on the page
     */
    private function photoFor(string $from, string $to, array $used = []): string
    {
        $cities = [mb_strtolower($from), mb_strtolower($to)];
        $has = static fn (string $name): bool => in_array($name, $cities, true);

        $preferred = match (true) {
            $has('essaouira') => 'images/route-essaouira-coast.jpg',
            $has('tanger') => 'images/route-tanger-corniche.jpg',
            $has('ouarzazate') => 'images/route-tizi-n-tichka.jpg',
            $has('agadir'), $has('marrakech') => 'images/route-agadir-marrakech-atlas.jpg',
            default => 'images/trip-windshield-road.jpg',
        };
        if (!in_array($preferred, $used, true)) {
            return $preferred;
        }

        foreach (self::PHOTOS as $photo) {
            if (!in_array($photo, $used, true)) {
                return $photo;
            }
        }

        return $preferred;
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
