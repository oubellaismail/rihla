<?php

namespace App\Service\Geo;

use App\Model\GeoPoint;
use App\Model\RouteLeg;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Road distance, driving time and line from OSRM, one leg at a time.
 * Everything is cached; failures are never cached and never thrown (null).
 */
final class OsrmClient implements RouteProvider
{
    private const TIMEOUT_SECONDS = 3;
    private const CACHE_TTL = 2592000; // 30 days

    public function __construct(
        private readonly HttpClientInterface $osrmClient,
        private readonly CacheInterface $geoCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function leg(GeoPoint $from, GeoPoint $to): ?RouteLeg
    {
        $coordinates = sprintf(
            '%s,%s;%s,%s',
            self::format($from->longitude),
            self::format($from->latitude),
            self::format($to->longitude),
            self::format($to->latitude),
        );

        try {
            $row = $this->geoCache->get(
                'osrm_leg_' . sha1($coordinates),
                function (ItemInterface $item) use ($coordinates): array {
                    $item->expiresAfter(self::CACHE_TTL);

                    return $this->request($coordinates);
                },
            );
        } catch (GeoFailure $e) {
            $this->logger->warning('OSRM route failed: ' . $e->getMessage());

            return null;
        }

        return new RouteLeg($row[0], $row[1], $row[2]);
    }

    public function route(array $points): ?array
    {
        if (\count($points) < 2) {
            throw new \InvalidArgumentException('A route needs at least two points.');
        }

        $legs = [];
        for ($i = 0, $n = \count($points) - 1; $i < $n; ++$i) {
            $leg = $this->leg($points[$i], $points[$i + 1]);
            if (null === $leg) {
                return null;
            }
            $legs[] = $leg;
        }

        return $legs;
    }

    /**
     * @return array{0: int, 1: int, 2: string} distance km, duration min, geometry
     *
     * @throws GeoFailure
     */
    private function request(string $coordinates): array
    {
        try {
            $response = $this->osrmClient->request('GET', '/route/v1/driving/' . $coordinates, [
                'query' => ['overview' => 'full', 'geometries' => 'polyline', 'steps' => 'false'],
                'max_duration' => self::TIMEOUT_SECONDS,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new GeoFailure($e->getMessage(), 0, $e);
        }

        $leg = \is_array($data) ? ($data['routes'][0]['legs'][0] ?? null) : null;
        $meters = \is_array($leg) ? ($leg['distance'] ?? null) : null;
        $seconds = \is_array($leg) ? ($leg['duration'] ?? null) : null;
        $geometry = \is_array($data) ? ($data['routes'][0]['geometry'] ?? null) : null;

        if (!\is_array($data) || 'Ok' !== ($data['code'] ?? null)
            || !is_numeric($meters) || !is_numeric($seconds) || !\is_string($geometry)) {
            throw new GeoFailure('OSRM found no route or answered unexpectedly.');
        }

        return [(int) round($meters / 1000), (int) round($seconds / 60), $geometry];
    }

    private static function format(float $degrees): string
    {
        return rtrim(rtrim(number_format($degrees, 5, '.', ''), '0'), '.');
    }
}
