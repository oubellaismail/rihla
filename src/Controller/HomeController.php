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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class HomeController extends AbstractController
{
    private const UPCOMING_TRIPS = 4;
    private const REVIEWS = 3;

    /** Routes offered as quick searches under the search bar. */

    /** Routes proposed to drivers when a route has no upcoming departure. */
    private const POPULAR_ROUTES = [
        ['Casablanca', 'Marrakech'], ['Agadir', 'Marrakech'], ['Essaouira', 'Marrakech'], ['Rabat', 'Casablanca'],
        ['Tanger', 'Rabat'], ['Marrakech', 'Ouarzazate'], ['Agadir', 'Essaouira'], ['Fès', 'Meknès'],
    ];

    /** Fallback photos for an arrival city without its own picture. */
    private const FALLBACK_PHOTOS = [
        'images/trip-windshield-road.jpg',
        'images/route-agadir-marrakech-atlas.jpg',
        'images/route-tizi-n-tichka.jpg',
        'images/route-essaouira-coast.jpg',
        'images/route-tanger-corniche.jpg',
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
        #[Autowire('%kernel.project_dir%')] string $projectDir,
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
            $photo = $this->photoFor($last->getCity()->getName(), $usedPhotos, $projectDir);
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
            'emptyRoute' => count($cards) < self::UPCOMING_TRIPS ? $this->routeWithoutDeparture(array_values(array_filter(self::POPULAR_ROUTES, $known)), $cities, $tripSearch) : null,
            'reviews' => $reviews->findLatestWithComment(self::REVIEWS),
        ]);
    }

    /**
     * The first popular route nobody leaves on yet: the page invites a driver to publish it.
     *
     * @param list<array{0: string, 1: string}> $pairs
     *
     * @return array{from: string, to: string}|null
     */
    private function routeWithoutDeparture(array $pairs, CityRepository $cities, TripSearch $tripSearch): ?array
    {
        foreach ($pairs as [$fromName, $toName]) {
            $from = $cities->findOneByNameInsensitive($fromName);
            $to = $cities->findOneByNameInsensitive($toName);
            if (null === $from || null === $to) {
                continue;
            }
            if ([] === array_filter($tripSearch->search($from, $to, null), static fn ($match): bool => !$match->full)) {
                return ['from' => $fromName, 'to' => $toName];
            }
        }

        return null;
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
     * The picture of the arrival city (images/city-<slug>.jpg), else the next unused fallback.
     *
     * @param list<string> $used photos already shown on the page
     */
    private function photoFor(string $arrival, array $used, string $projectDir): string
    {
        $slug = (new AsciiSlugger())->slug($arrival)->lower()->toString();
        $own = sprintf('images/city-%s.jpg', $slug);
        if (is_file($projectDir.'/assets/'.$own)) {
            return $own;
        }

        foreach (self::FALLBACK_PHOTOS as $photo) {
            if (!in_array($photo, $used, true)) {
                return $photo;
            }
        }

        return self::FALLBACK_PHOTOS[0];
    }
}
