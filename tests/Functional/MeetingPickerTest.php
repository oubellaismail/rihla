<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Tests\Support\DbTrips;
use App\Tests\Support\FakeGeoApis;

/**
 * The meeting-point picker (approved mockups « Point de rendez-vous, grande carte / téléphone »):
 * the map opens on the stop's city, the API says how far a place is from that city, suggests
 * places other drivers already used there, and a point more than 15 km away is refused.
 * Nominatim is faked: a search answers « <q>, Maroc » at 31.5, -9.7 (≈ 6 km from Essaouira).
 */
final class MeetingPickerTest extends WebFlowTestCase
{
    private function getJson(string $url): array
    {
        $this->client->request('GET', $url);

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function loggedIn(): User
    {
        $karim = $this->db->user('Karim');
        $this->login($karim);

        return $karim;
    }

    private function cityId(string $name): int
    {
        $city = $this->db->city($name);
        $this->em->flush();

        return $city->getId();
    }

    // ---- API: city-aware search and reverse --------------------------------------------------

    public function testSearchingInACityAddsTheCityAndSaysHowFarEachPlaceIs(): void
    {
        $this->loggedIn();
        $essaouira = $this->cityId('Essaouira');

        $data = $this->getJson(sprintf('/api/geocode?q=%s&city=%d', rawurlencode('Bab Marrakech'), $essaouira));

        self::assertResponseIsSuccessful();
        parse_str((string) parse_url(FakeGeoApis::$calls[0], PHP_URL_QUERY), $sent);
        self::assertSame('Bab Marrakech, Essaouira', $sent['q'], 'the city name is added to the search');
        $place = $data['places'][0];
        self::assertSame(['label', 'lat', 'lng', 'distanceKm', 'tooFar'], array_keys($place));
        self::assertSame('Bab Marrakech, Essaouira', $place['label']);
        self::assertEqualsWithDelta(5.7, $place['distanceKm'], 0.3);
        self::assertFalse($place['tooFar']);
    }

    public function testAPlaceFarFromTheCityIsFlagged(): void
    {
        $this->loggedIn();
        $agadir = $this->cityId('Agadir');

        $place = $this->getJson(sprintf('/api/geocode?q=Gare&city=%d', $agadir))['places'][0]; // the fake answers near Essaouira

        self::assertGreaterThan(100, $place['distanceKm']);
        self::assertTrue($place['tooFar']);
    }

    public function testReverseInACitySaysHowFarThePointIs(): void
    {
        $this->loggedIn();
        $essaouira = $this->cityId('Essaouira');

        $place = $this->getJson(sprintf('/api/reverse?lat=31.5126&lng=-9.77&city=%d', $essaouira))['place'];

        self::assertEqualsWithDelta(1.1, $place['distanceKm'], 0.3);
        self::assertFalse($place['tooFar']);
    }

    public function testWithoutACityTheAnswersStayAsBefore(): void
    {
        $this->loggedIn();

        $place = $this->getJson('/api/reverse?lat=31.5126&lng=-9.77')['place'];

        self::assertSame(['label', 'lat', 'lng'], array_keys($place));
    }

    public function testAnUnknownCityIsNotFound(): void
    {
        $this->loggedIn();

        $this->client->request('GET', '/api/geocode?q=Gare&city=999999');

        self::assertResponseStatusCodeSame(404);
    }

    // ---- API: places other drivers already used in the city ----------------------------------

    /** A published trip from $city whose first stop has this meeting point. */
    private function tripMeetingAt(string $city, string $label, ?float $lat, ?float $lng): Trip
    {
        $trip = $this->db->trip(stops: [[$city, '08:00', 50], ['Marrakech', '11:00', null]]);
        DbTrips::stop($trip, 0)->setMeetingPoint($label)->setMeetingLatitude($lat)->setMeetingLongitude($lng);
        $this->em->flush();

        return $trip;
    }

    public function testSuggestsThePlacesAlreadyUsedInTheCityMostUsedFirst(): void
    {
        $this->loggedIn();
        $this->tripMeetingAt('Essaouira', 'Gare routière', 31.5155, -9.7633);
        $this->tripMeetingAt('Essaouira', 'Bab Marrakech', 31.5126, -9.77);
        $this->tripMeetingAt('Essaouira', 'Bab Marrakech', 31.5126, -9.77);
        $this->tripMeetingAt('Essaouira', 'Centre-ville', null, null);       // never placed on a map: not suggested
        $this->tripMeetingAt('Agadir', 'Gare routière, avenue des FAR', 30.4202, -9.5982); // another city

        $data = $this->getJson(sprintf('/api/meeting-points?city=%d', $this->cityId('Essaouira')));

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['label' => 'Bab Marrakech', 'lat' => 31.5126, 'lng' => -9.77, 'uses' => 2],
            ['label' => 'Gare routière', 'lat' => 31.5155, 'lng' => -9.7633, 'uses' => 1],
        ], $data['places']);
    }

    public function testAtMostFiveSuggestions(): void
    {
        $this->loggedIn();
        foreach (range(1, 7) as $i) {
            $this->tripMeetingAt('Essaouira', 'Lieu '.$i, 31.51 + $i / 1000, -9.76);
        }

        self::assertCount(5, $this->getJson(sprintf('/api/meeting-points?city=%d', $this->cityId('Essaouira')))['places']);
    }

    public function testSuggestionsNeedALogin(): void
    {
        $this->client->request('GET', sprintf('/api/meeting-points?city=%d', $this->cityId('Essaouira')));

        self::assertResponseRedirects('/login');
    }

    // ---- publish page ------------------------------------------------------------------------

    public function testThePublishPageHasThePickerWithTheFifteenKilometreLimit(): void
    {
        $karim = $this->loggedIn();
        $this->db->vehicle($karim);
        $this->em->flush();

        $this->client->request('GET', '/trips/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="meeting-picker"][data-meeting-picker-max-km-value="15"]');
        self::assertSelectorExists('[role="dialog"][data-meeting-picker-target="dialog"]');
        self::assertSelectorTextContains('button[data-action~="meeting-picker#open"]', 'Choisir sur la carte');
        self::assertSelectorExists('input[type="hidden"][name="trip[stops][0][meetingLatitude]"]', 'still the same hidden fields');
    }

    public function testPublishingWithAPointFarFromTheCityShowsWhy(): void
    {
        $karim = $this->loggedIn();
        $vehicle = $this->db->vehicle($karim);
        $agadir = $this->db->city('Agadir');
        $essaouira = $this->db->city('Essaouira');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/trips/new');
        $form = $crawler->selectButton('Publier le trajet')->form();
        $values = $form->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        $values['trip']['stops'] = [
            ['city' => $agadir->getId(), 'departureAt' => '2026-11-12T08:00', 'meetingPoint' => 'Gare routière', 'legPriceToNext' => 60, 'meetingLatitude' => '30.4202', 'meetingLongitude' => '-9.5982'],
            ['city' => $essaouira->getId(), 'departureAt' => '2026-11-12T10:15', 'meetingPoint' => 'Station Ounagha', 'legPriceToNext' => '', 'meetingLatitude' => '31.5311', 'meetingLongitude' => '-9.4383'],
        ];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertMatchesRegularExpression('/Essaouira.*\b(29|30|31) km/u', $this->pageText());
        self::assertNull($this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]));
    }
}
