<?php

namespace App\Service\Geo;

use App\Model\GeoPlace;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Address ⇄ coordinates through Nominatim (OpenStreetMap).
 *
 * Usage policy respected: identifying User-Agent (see http client config), results cached,
 * at most one request per second for the whole app (rate limiter). Failures are never cached
 * and never thrown: the caller just gets "nothing found".
 */
final class NominatimClient implements GeoProvider
{
    private const TIMEOUT_SECONDS = 3;
    private const CACHE_TTL = 2592000; // 30 days
    private const MAX_WAIT_SECONDS = 2.0;

    public function __construct(
        private readonly HttpClientInterface $nominatimClient,
        private readonly CacheInterface $geoCache,
        private readonly LoggerInterface $logger,
        private readonly ?RateLimiterFactory $nominatimLimiter = null,
    ) {
    }

    public function search(string $query): array
    {
        $normalised = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($query)));
        if ('' === $normalised) {
            return [];
        }

        try {
            $rows = $this->geoCache->get(
                'nominatim_search_' . sha1($normalised),
                function (ItemInterface $item) use ($query): array {
                    $item->expiresAfter(self::CACHE_TTL);

                    $data = $this->request('/search', [
                        'q' => trim($query),
                        'format' => 'jsonv2',
                        'countrycodes' => 'ma',
                        'limit' => 5,
                    ]);
                    if (!array_is_list($data)) {
                        throw new GeoFailure('Unexpected search answer.');
                    }

                    $rows = [];
                    foreach ($data as $place) {
                        $row = self::toRow($place);
                        if (null !== $row) {
                            $rows[] = $row;
                        }
                    }

                    return $rows;
                },
            );
        } catch (GeoFailure $e) {
            $this->logger->warning('Nominatim search failed: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn (array $r) => new GeoPlace($r[0], $r[1], $r[2]), $rows);
    }

    public function reverse(float $lat, float $lng): ?GeoPlace
    {
        try {
            $row = $this->geoCache->get(
                sprintf('nominatim_reverse_%s_%s', sha1(sprintf('%.5f', $lat)), sha1(sprintf('%.5f', $lng))),
                function (ItemInterface $item) use ($lat, $lng): array {
                    $item->expiresAfter(self::CACHE_TTL);

                    $data = $this->request('/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2']);
                    $row = self::toRow($data);
                    if (null === $row) {
                        throw new GeoFailure('Nothing found at this point.');
                    }

                    return $row;
                },
            );
        } catch (GeoFailure $e) {
            $this->logger->warning('Nominatim reverse failed: ' . $e->getMessage());

            return null;
        }

        return new GeoPlace($row[0], $row[1], $row[2]);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     *
     * @throws GeoFailure
     */
    private function request(string $path, array $query): array
    {
        try {
            $this->nominatimLimiter?->create('nominatim')->reserve(1, self::MAX_WAIT_SECONDS)->wait();

            $response = $this->nominatimClient->request('GET', $path, [
                'query' => $query,
                'max_duration' => self::TIMEOUT_SECONDS,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new GeoFailure($e->getMessage(), 0, $e);
        }

        if (!\is_array($data) || isset($data['error'])) {
            throw new GeoFailure('Nominatim answered with an error or an unexpected body.');
        }

        return $data;
    }

    /** @return array{0: string, 1: float, 2: float}|null label, latitude, longitude */
    private static function toRow(mixed $place): ?array
    {
        if (!\is_array($place) || !isset($place['display_name'], $place['lat'], $place['lon'])
            || !is_numeric($place['lat']) || !is_numeric($place['lon'])) {
            return null;
        }
        $parts = array_map('trim', explode(', ', (string) $place['display_name']));

        return [implode(', ', \array_slice($parts, 0, 2)), (float) $place['lat'], (float) $place['lon']];
    }
}
