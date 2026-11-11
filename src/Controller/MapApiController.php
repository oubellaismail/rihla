<?php

namespace App\Controller;

use App\Entity\User;
use App\Model\GeoPlace;
use App\Model\GeoPoint;
use App\Service\Geo\ArrivalTimeSuggester;
use App\Service\Geo\GeoProvider;
use App\Service\Geo\PriceSuggester;
use App\Service\Geo\RouteProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON endpoints for the publish map. The browser never calls Nominatim or OSRM itself:
 * it calls these, which apply the cache and the limits.
 */
#[Route('/api', methods: ['GET'])]
final class MapApiController extends AbstractController
{
    private const MIN_QUERY_LENGTH = 3;
    private const MIN_POINTS = 2;
    private const MAX_POINTS = 10;

    public function __construct(private readonly RateLimiterFactory $mapApiLimiter)
    {
    }

    #[Route('/geocode', name: 'app_api_geocode')]
    public function geocode(Request $request, GeoProvider $geo): JsonResponse
    {
        if ($limited = $this->tooManyRequests()) {
            return $limited;
        }

        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return new JsonResponse(['places' => []]);
        }

        return new JsonResponse(['places' => array_map(self::placeToArray(...), $geo->search($query))]);
    }

    #[Route('/reverse', name: 'app_api_reverse')]
    public function reverse(Request $request, GeoProvider $geo): JsonResponse
    {
        if ($limited = $this->tooManyRequests()) {
            return $limited;
        }

        $lat = self::number($request->query->get('lat'));
        $lng = self::number($request->query->get('lng'));
        if (null === $lat || null === $lng || !self::isOnEarth($lat, $lng)) {
            return new JsonResponse(['error' => 'Coordonnées invalides.'], 400);
        }

        $place = $geo->reverse($lat, $lng);

        return new JsonResponse(['place' => null === $place ? null : self::placeToArray($place)]);
    }

    #[Route('/route', name: 'app_api_route')]
    public function route(
        Request $request,
        RouteProvider $routes,
        PriceSuggester $prices,
        ArrivalTimeSuggester $arrivals,
    ): JsonResponse {
        if ($limited = $this->tooManyRequests()) {
            return $limited;
        }

        $points = $this->parsePoints((string) $request->query->get('points', ''));
        if (null === $points) {
            return new JsonResponse(['error' => 'Indiquez entre 2 et 10 points valides.'], 400);
        }

        $legs = $routes->route($points);
        if (null === $legs) {
            return new JsonResponse(['legs' => null], 503);
        }

        $departures = explode(',', (string) $request->query->get('departures', ''));
        $data = [];
        foreach ($legs as $i => $leg) {
            $next = null;
            $departure = self::parseDeparture($departures[$i] ?? '');
            if (null !== $departure) {
                $next = $arrivals->nextDeparture($departure, $leg->durationMin)->format('Y-m-d\TH:i');
            }
            $data[] = [
                'distanceKm' => $leg->distanceKm,
                'durationMin' => $leg->durationMin,
                'suggestedPrice' => $prices->forDistance($leg->distanceKm),
                'geometry' => $leg->geometry,
                'suggestedNextDeparture' => $next,
            ];
        }

        return new JsonResponse(['legs' => $data]);
    }

    private function tooManyRequests(): ?JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $limit = $this->mapApiLimiter->create((string) $user->getUserIdentifier())->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return new JsonResponse(['error' => 'Trop de requêtes, réessayez dans un instant.'], 429);
    }

    /** @return array{label: string, lat: float, lng: float} */
    private static function placeToArray(GeoPlace $place): array
    {
        return ['label' => $place->label, 'lat' => $place->latitude, 'lng' => $place->longitude];
    }

    private static function number(mixed $value): ?float
    {
        return \is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    private static function isOnEarth(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /** @return list<GeoPoint>|null null unless there are 2 to 10 valid "lat,lng" points */
    private function parsePoints(string $raw): ?array
    {
        $parts = '' === trim($raw) ? [] : explode(';', $raw);
        if (\count($parts) < self::MIN_POINTS || \count($parts) > self::MAX_POINTS) {
            return null;
        }

        $points = [];
        foreach ($parts as $part) {
            $pair = explode(',', $part);
            $lat = 2 === \count($pair) ? self::number(trim($pair[0])) : null;
            $lng = 2 === \count($pair) ? self::number(trim($pair[1])) : null;
            if (null === $lat || null === $lng || !self::isOnEarth($lat, $lng)) {
                return null;
            }
            $points[] = new GeoPoint($lat, $lng);
        }

        return $points;
    }

    private static function parseDeparture(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim($value));

        return false === $date ? null : $date;
    }
}
