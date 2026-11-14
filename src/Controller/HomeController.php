<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Repository\CityRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use App\Service\PriceCalculator;
use App\Service\SeatAvailabilityCalculator;
use App\Service\TripSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    private const UPCOMING_TRIPS = 4;
    private const REVIEWS = 3;

    /** Routes offered as quick searches under the search bar. */
    private const QUICK_ROUTES = [['Essaouira', 'Marrakech'], ['Casablanca', 'Rabat'], ['Agadir', 'Marrakech']];

    private const MIN_ROUTE_TILES = 4;
    private const MAX_ROUTE_TILES = 8;

    /** Routes shown as tiles in « Routes populaires » when trips are scarce. */
    private const POPULAR_ROUTES = [
        ['Casablanca', 'Marrakech'], ['Agadir', 'Marrakech'], ['Essaouira', 'Marrakech'], ['Rabat', 'Casablanca'],
        ['Tanger', 'Rabat'], ['Marrakech', 'Ouarzazate'], ['Agadir', 'Essaouira'], ['Fès', 'Meknès'],
    ];

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
        ReviewRepository $reviews,
        SeatAvailabilityCalculator $seatAvailability,
        PriceCalculator $priceCalculator,
        TripSearch $tripSearch,
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

        $cityNames = $cities->findAllNames();
        $known = static fn (array $pair): bool => in_array($pair[0], $cityNames, true) && in_array($pair[1], $cityNames, true);

        return $this->render('home/index.html.twig', [
            'today' => $now->format('Y-m-d'),
            'cityNames' => $cityNames,
            'cards' => $cards,
            'nextDeparture' => $this->nextDeparture($cards),
            'quickRoutes' => array_values(array_filter(self::QUICK_ROUTES, $known)),
            'popularRoutes' => $this->popularRoutes($this->routeCandidates($trips->findUpcoming($now, 20), array_values(array_filter(self::POPULAR_ROUTES, $known))), $cities, $tripSearch),
            'reviews' => $reviews->findLatestWithComment(self::REVIEWS),
        ]);
    }

    /**
     * The soonest trip on the page that still has a free seat somewhere.
     *
     * @param list<array{trip: Trip, freeSeats: int, someLegFree: bool, price: int}> $cards
     *
     * @return array{trip: Trip, freeSeats: int, someLegFree: bool, price: int}|null
     */
    private function nextDeparture(array $cards): ?array
    {
        foreach ($cards as $card) {
            if ($card['someLegFree']) {
                return $card;
            }
        }

        return null;
    }

    /**
     * The usual routes, plus the routes of trips about to leave, without duplicates.
     *
     * @param list<Trip>                        $upcoming
     * @param list<array{0: string, 1: string}> $usual
     *
     * @return list<array{0: string, 1: string}>
     */
    private function routeCandidates(array $upcoming, array $usual): array
    {
        $pairs = [];
        foreach ($upcoming as $trip) {
            $pairs[] = [$trip->getFirstStop()->getCity()->getName(), $trip->getLastStop()->getCity()->getName()];
        }
        foreach ($usual as $pair) {
            $pairs[] = $pair;
        }

        $unique = [];
        foreach ($pairs as $pair) {
            $unique[$pair[0].'|'.$pair[1]] ??= $pair;
        }

        return array_values($unique);
    }

    /**
     * Each popular route with its upcoming departures and the lowest seat price among them.
     * Routes with departures come first; the others invite drivers to publish.
     *
     * @param list<array{0: string, 1: string}> $pairs
     *
     * @return list<array{from: string, to: string, departures: int, price: ?int, next: ?\DateTimeImmutable}>
     */
    private function popularRoutes(array $pairs, CityRepository $cities, TripSearch $tripSearch): array
    {
        $routes = [];
        foreach ($pairs as [$fromName, $toName]) {
            $from = $cities->findOneByNameInsensitive($fromName);
            $to = $cities->findOneByNameInsensitive($toName);
            if (null === $from || null === $to) {
                continue;
            }
            $open = array_filter($tripSearch->search($from, $to, null), static fn ($match): bool => !$match->full);
            $routes[] = [
                'from' => $fromName,
                'to' => $toName,
                'departures' => count($open),
                'price' => [] === $open ? null : min(array_map(static fn ($match): int => $match->pricePerSeat, $open)),
                'next' => [] === $open ? null : reset($open)->from->getDepartureAt(),
            ];
        }
        usort($routes, static fn (array $a, array $b): int => $b['departures'] <=> $a['departures']);
        $open = array_values(array_filter($routes, static fn (array $route): bool => $route['departures'] > 0));
        $empty = array_values(array_filter($routes, static fn (array $route): bool => 0 === $route['departures']));

        // Empty routes only fill the grid up to one row, so the section never looks deserted.
        return array_slice([...$open, ...array_slice($empty, 0, max(0, self::MIN_ROUTE_TILES - count($open)))], 0, self::MAX_ROUTE_TILES);
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
}
