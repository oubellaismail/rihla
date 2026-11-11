<?php

namespace App\Tests\Unit\Service\Geo;

use App\Model\GeoPoint;
use App\Model\RouteLeg;
use App\Service\Geo\OsrmClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Road distance, driving time and the line to draw, from OSRM, one leg at a time
 * (one call per leg gives one line per leg, so the map can highlight a passenger's portion).
 * Fair use of the public demo server: few calls, all cached. Down or slow → null, never an exception.
 */
final class OsrmClientTest extends TestCase
{
    private const AGADIR_ESSAOUIRA = '{"code":"Ok","routes":[{"geometry":"_p~iF~ps|U_ulLnnqC","legs":[{"distance":175123.4,"duration":7480.2}]}]}';

    private GeoPoint $agadir;
    private GeoPoint $essaouira;
    private GeoPoint $marrakech;

    /** @var list<MockResponse> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->agadir = new GeoPoint(30.4278, -9.5981);
        $this->essaouira = new GeoPoint(31.5085, -9.7595);
        $this->marrakech = new GeoPoint(31.6295, -7.9811);
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): OsrmClient
    {
        $this->requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$responses) {
            $response = array_shift($responses) ?? throw new \LogicException('Unexpected extra HTTP call to ' . $url);
            $this->requests[] = $response;

            return $response;
        }, 'https://osrm.test');

        return new OsrmClient($http, new ArrayAdapter(), new NullLogger());
    }

    public function testALegHasItsDistanceInKmItsDurationInMinutesAndItsLine(): void
    {
        $leg = $this->client([new MockResponse(self::AGADIR_ESSAOUIRA)])->leg($this->agadir, $this->essaouira);

        self::assertEquals(new RouteLeg(175, 125, '_p~iF~ps|U_ulLnnqC'), $leg);
    }

    public function testCoordinatesAreSentLongitudeFirstAsOsrmExpects(): void
    {
        $this->client([new MockResponse(self::AGADIR_ESSAOUIRA)])->leg($this->agadir, $this->essaouira);

        $url = $this->requests[0]->getRequestUrl();
        self::assertStringStartsWith('https://osrm.test/route/v1/driving/-9.5981,30.4278;-9.7595,31.5085', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('full', $query['overview']);
        self::assertSame('polyline', $query['geometries']);
    }

    public function testEveryCallGivesUpAfterThreeSeconds(): void
    {
        $this->client([new MockResponse(self::AGADIR_ESSAOUIRA)])->leg($this->agadir, $this->essaouira);

        $options = $this->requests[0]->getRequestOptions();
        self::assertGreaterThan(0, $options['max_duration']);
        self::assertLessThanOrEqual(3, $options['max_duration']);
    }

    public function testTheSameLegIsAskedOnlyOnce(): void
    {
        $client = $this->client([new MockResponse(self::AGADIR_ESSAOUIRA)]);

        $client->leg($this->agadir, $this->essaouira);
        $again = $client->leg(new GeoPoint(30.427801, -9.598099), $this->essaouira); // same point, a few cm away

        self::assertCount(1, $this->requests);
        self::assertSame(175, $again?->distanceKm);
    }

    public function testNoRoadMeansNoLeg(): void
    {
        self::assertNull($this->client([new MockResponse('{"code":"NoRoute","message":"Impossible route between points"}')])
            ->leg($this->agadir, new GeoPoint(28.0, -20.0)));
    }

    public function testAnOutageMeansNoLegAndIsNotCached(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['error' => 'Idle timeout reached']),
            new MockResponse('not json'),
            new MockResponse(self::AGADIR_ESSAOUIRA),
        ]);

        self::assertNull($client->leg($this->agadir, $this->essaouira), '503');
        self::assertNull($client->leg($this->agadir, $this->essaouira), 'timeout');
        self::assertNull($client->leg($this->agadir, $this->essaouira), 'garbage');
        self::assertNotNull($client->leg($this->agadir, $this->essaouira), 'failures are not cached');
    }

    public function testARouteThroughSeveralStopsIsOneLegPerPair(): void
    {
        $client = $this->client([
            new MockResponse(self::AGADIR_ESSAOUIRA),
            new MockResponse('{"code":"Ok","routes":[{"geometry":"abc","legs":[{"distance":190400,"duration":9600}]}]}'),
        ]);

        $legs = $client->route([$this->agadir, $this->essaouira, $this->marrakech]);

        self::assertEquals([new RouteLeg(175, 125, '_p~iF~ps|U_ulLnnqC'), new RouteLeg(190, 160, 'abc')], $legs);
    }

    public function testARouteWithOneMissingLegIsNull(): void
    {
        $client = $this->client([
            new MockResponse(self::AGADIR_ESSAOUIRA),
            new MockResponse('', ['http_code' => 503]),
        ]);

        self::assertNull($client->route([$this->agadir, $this->essaouira, $this->marrakech]));
    }

    public function testARouteNeedsTwoPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->route([$this->agadir]);
    }
}
