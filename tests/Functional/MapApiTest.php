<?php

namespace App\Tests\Functional;

use App\Tests\Support\FakeGeoApis;

/**
 * Our JSON endpoints used by the publish map. The browser never calls Nominatim or OSRM itself:
 * it calls these, which apply the cache and the limits. Nominatim and OSRM are faked (FakeGeoApis).
 */
final class MapApiTest extends WebFlowTestCase
{
    private function getJson(string $url): array
    {
        $this->client->request('GET', $url);

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheApiIsForLoggedInUsersOnly(): void
    {
        foreach (['/api/geocode?q=Agadir', '/api/reverse?lat=31.5&lng=-9.7', '/api/route?points=30.4278,-9.5981;31.5085,-9.7595'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', null, $url);
        }
        self::assertSame([], FakeGeoApis::$calls);
    }

    public function testGeocodeReturnsPlaces(): void
    {
        $this->login($this->db->user('Karim'));

        $data = $this->getJson('/api/geocode?q=' . rawurlencode('Gare routière Agadir'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['places' => [['label' => 'Gare routière Agadir, Maroc', 'lat' => 31.5, 'lng' => -9.7]]], $data);
    }

    public function testATooShortQueryFindsNothingWithoutCallingNominatim(): void
    {
        $this->login($this->db->user('Karim'));

        self::assertSame(['places' => []], $this->getJson('/api/geocode?q=ab'));
        self::assertSame([], FakeGeoApis::$calls);
    }

    public function testReverseReturnsTheAddressOfAPoint(): void
    {
        $this->login($this->db->user('Karim'));

        $data = $this->getJson('/api/reverse?lat=31.5126&lng=-9.77');

        self::assertSame(['place' => ['label' => 'Rue de test, 31.5126,-9.77', 'lat' => 31.5126, 'lng' => -9.77]], $data);
    }

    public function testReverseRejectsCoordinatesThatDoNotExist(): void
    {
        $this->login($this->db->user('Karim'));

        foreach (['/api/reverse?lat=95&lng=-9.7', '/api/reverse?lat=abc&lng=-9.7', '/api/reverse?lat=31.5'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(400, $url);
        }
    }

    public function testRouteGivesEachLegWithASuggestedPrice(): void
    {
        $this->login($this->db->user('Karim'));

        $data = $this->getJson('/api/route?points=30.4278,-9.5981;31.5085,-9.7595;31.6295,-7.9811');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $data['legs']);
        foreach ($data['legs'] as $leg) {
            self::assertSame(['distanceKm', 'durationMin', 'suggestedPrice', 'geometry', 'suggestedNextDeparture'], array_keys($leg));
            self::assertSame((int) (round($leg['distanceKm'] * 0.35 / 5) * 5), $leg['suggestedPrice'], 'PriceSuggester');
            self::assertSame('fake-polyline', $leg['geometry']);
            self::assertNull($leg['suggestedNextDeparture'], 'no departure times were given');
        }
    }

    public function testRouteSuggestsTheNextDepartureWhenTheTimesAreKnown(): void
    {
        $this->login($this->db->user('Karim'));

        $data = $this->getJson('/api/route?points=30.4278,-9.5981;31.5085,-9.7595&departures=2026-11-12T08:00');

        $leg = $data['legs'][0];
        $expected = (new \DateTimeImmutable('2026-11-12 08:00'))->modify(sprintf('+%d minutes', $leg['durationMin'] + 10));
        $minutes = (int) $expected->format('i');
        if (0 !== $minutes % 5) {
            $expected = $expected->modify(sprintf('+%d minutes', 5 - $minutes % 5));
        }
        self::assertSame($expected->format('Y-m-d\TH:i'), $leg['suggestedNextDeparture'], 'ArrivalTimeSuggester');
    }

    public function testRouteNeedsAtLeastTwoValidPoints(): void
    {
        $this->login($this->db->user('Karim'));

        foreach (['/api/route?points=30.4278,-9.5981', '/api/route?points=30.4,-9.5;north', '/api/route', '/api/route?points=' . str_repeat('30.4,-9.5;', 12) . '30.4,-9.5'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(400, $url);
        }
    }

    public function testWhenOsrmIsDownTheRouteSaysSoInsteadOfFailing(): void
    {
        $this->login($this->db->user('Karim'));
        FakeGeoApis::$down = true;

        $data = $this->getJson('/api/route?points=30.4278,-9.5981;31.5085,-9.7595');

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['legs' => null], $data);
    }

    public function testWhenNominatimIsDownNothingIsFound(): void
    {
        $this->login($this->db->user('Karim'));
        FakeGeoApis::$down = true;

        self::assertSame(['places' => []], $this->getJson('/api/geocode?q=Essaouira'));
        self::assertResponseIsSuccessful();
        self::assertSame(['place' => null], $this->getJson('/api/reverse?lat=31.5&lng=-9.7'));
    }

    public function testOneUserCannotFloodTheApi(): void
    {
        $this->login($this->db->user('Karim'));

        for ($i = 1; $i <= 30; ++$i) {
            $this->client->request('GET', '/api/geocode?q=Agadir');
            self::assertResponseIsSuccessful("call $i");
        }
        $this->client->request('GET', '/api/geocode?q=Agadir');

        self::assertResponseStatusCodeSame(429);
    }
}
