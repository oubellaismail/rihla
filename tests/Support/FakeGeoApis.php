<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for Nominatim and OSRM in the test environment, so no test ever reaches the network.
 * Wired as framework.http_client.mock_response_factory (when@test).
 *
 * - OSRM: distance = straight line × 1.3, at 80 km/h; geometry "fake-polyline".
 * - Nominatim search: one place, labelled after the query, at 31.5, -9.7.
 * - Nominatim reverse: « Rue de test, <lat>,<lng> ».
 * - FakeGeoApis::$down = true makes every call answer 503 (an outage).
 */
final class FakeGeoApis
{
    public static bool $down = false;

    /** @var list<string> every URL requested since the last reset() */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$down = false;
        self::$calls = [];
    }

    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$calls[] = $url;
        if (self::$down) {
            return new MockResponse('', ['http_code' => 503]);
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (preg_match('#/route/v1/driving/([^/?]+)#', $path, $m)) {
            return $this->route(urldecode($m[1]));
        }
        if (str_ends_with($path, '/search')) {
            return self::json([[
                'display_name' => trim((string) ($query['q'] ?? '')) . ', Maroc',
                'lat' => '31.5',
                'lon' => '-9.7',
            ]]);
        }
        if (str_ends_with($path, '/reverse')) {
            return self::json([
                'display_name' => sprintf('Rue de test, %s,%s, Maroc', $query['lat'] ?? '', $query['lon'] ?? ''),
                'lat' => (string) ($query['lat'] ?? ''),
                'lon' => (string) ($query['lon'] ?? ''),
            ]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }

    private function route(string $coordinates): MockResponse
    {
        $points = array_map(static fn (string $p) => array_map('floatval', explode(',', $p)), explode(';', $coordinates));
        $legs = [];
        for ($i = 0; $i < \count($points) - 1; ++$i) {
            [$lng1, $lat1] = $points[$i];
            [$lng2, $lat2] = $points[$i + 1];
            $meters = self::haversineKm($lat1, $lng1, $lat2, $lng2) * 1.3 * 1000;
            $legs[] = ['distance' => $meters, 'duration' => $meters / 1000 / 80 * 3600];
        }

        return self::json(['code' => 'Ok', 'routes' => [['geometry' => 'fake-polyline', 'legs' => $legs]]]);
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
