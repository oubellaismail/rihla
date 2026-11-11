<?php

namespace App\Tests\Unit\Service\Geo;

use App\Model\GeoPlace;
use App\Service\Geo\NominatimClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Address ⇄ coordinates through Nominatim (OpenStreetMap).
 * Usage policy: at most 1 request per second, an identifying User-Agent, cache the results.
 * When Nominatim is slow or down the client returns "nothing found" instead of throwing:
 * the driver can still place the point on the map or type the address.
 */
final class NominatimClientTest extends TestCase
{
    private const ESSAOUIRA = '[{"place_id":1,"lat":"31.5126","lon":"-9.7700","display_name":"Bab Marrakech, Essaouira, Pachalik d\'Essaouira, Marrakech-Safi, Maroc"}]';

    /** @var list<MockResponse> */
    private array $requests = [];

    /** @param list<MockResponse|callable> $responses */
    private function client(array $responses): NominatimClient
    {
        $this->requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $next = array_shift($responses) ?? throw new \LogicException('Unexpected extra HTTP call to ' . $url);
            $response = \is_callable($next) ? $next($method, $url, $options) : $next;
            $this->requests[] = $response;

            return $response;
        }, 'https://nominatim.test');

        return new NominatimClient($http, new ArrayAdapter(), new NullLogger());
    }

    public function testSearchReturnsPlacesWithAShortLabelAndFloatCoordinates(): void
    {
        $places = $this->client([new MockResponse(self::ESSAOUIRA)])->search('Bab Marrakech Essaouira');

        self::assertEquals([new GeoPlace('Bab Marrakech, Essaouira', 31.5126, -9.77)], $places);
    }

    public function testSearchIsLimitedToMoroccoAndAsksForJson(): void
    {
        $this->client([new MockResponse('[]')])->search('Gare routière');

        $url = $this->requests[0]->getRequestUrl();
        self::assertStringStartsWith('https://nominatim.test/search?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('Gare routière', $query['q']);
        self::assertSame('ma', $query['countrycodes']);
        self::assertSame('jsonv2', $query['format']);
        self::assertLessThanOrEqual(5, (int) $query['limit']);
    }

    public function testEveryCallGivesUpAfterThreeSeconds(): void
    {
        $this->client([new MockResponse('[]')])->search('Agadir');

        $options = $this->requests[0]->getRequestOptions();
        self::assertGreaterThan(0, $options['max_duration']);
        self::assertLessThanOrEqual(3, $options['max_duration']);
    }

    public function testAnEmptyQueryDoesNotCallNominatim(): void
    {
        self::assertSame([], $this->client([])->search('   '));
    }

    public function testTheSameSearchIsAskedOnlyOnce(): void
    {
        $client = $this->client([new MockResponse(self::ESSAOUIRA)]);

        $first = $client->search('Bab Marrakech Essaouira');
        $second = $client->search('  bab marrakech essaouira '); // same words, other spacing and case

        self::assertCount(1, $this->requests);
        self::assertEquals($first, $second);
    }

    public function testReverseTurnsAPointIntoAnAddress(): void
    {
        $client = $this->client([new MockResponse('{"lat":"31.5126","lon":"-9.7700","display_name":"Bab Marrakech, Essaouira, Maroc"}')]);

        $place = $client->reverse(31.5126, -9.77);

        self::assertEquals(new GeoPlace('Bab Marrakech, Essaouira', 31.5126, -9.77), $place);
        self::assertStringStartsWith('https://nominatim.test/reverse?', $this->requests[0]->getRequestUrl());
    }

    public function testReverseInTheSeaFindsNothing(): void
    {
        self::assertNull($this->client([new MockResponse('{"error":"Unable to geocode"}')])->reverse(31.0, -12.0));
    }

    public function testAnOutageMeansNothingFoundAndIsNotCached(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['error' => 'Idle timeout reached']),
            new MockResponse(self::ESSAOUIRA),
        ]);

        self::assertSame([], $client->search('Essaouira'), '503');
        self::assertNull($client->reverse(31.5, -9.7), 'timeout');
        self::assertCount(1, $client->search('Essaouira'), 'the failure was not cached, so the next try works');
    }

    public function testAnswersThatAreNotJsonAreTreatedAsAnOutage(): void
    {
        self::assertSame([], $this->client([new MockResponse('<html>Bandwidth limit exceeded</html>')])->search('Agadir'));
    }
}
