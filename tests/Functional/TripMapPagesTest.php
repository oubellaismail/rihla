<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Tests\Support\DbTrips;
use App\Tests\Support\FakeGeoApis;

/**
 * The maps in the pages. The map itself is drawn by Leaflet in the browser (Stimulus controllers);
 * here we check what the server gives it, and above all what it does NOT give it.
 *
 * Contract with the front end:
 *   trip, booking and driver pages: an element [data-controller~="trip-map"] whose
 *   data-trip-map-data-value attribute holds the TripMapBuilder data as JSON;
 *   publish page: [data-controller~="publish-map"] and two hidden fields per stop,
 *   trip[stops][i][meetingLatitude] and trip[stops][i][meetingLongitude].
 */
final class TripMapPagesTest extends WebFlowTestCase
{
    private const BOARDING = [31.5126, -9.77]; // Bab Marrakech, Essaouira

    private function tripWithMeetingPoints(): Trip
    {
        $trip = $this->db->trip(driver: $this->db->user('Karim'));
        DbTrips::stop($trip, 1)->setMeetingLatitude(self::BOARDING[0])->setMeetingLongitude(self::BOARDING[1]);
        DbTrips::stop($trip, 2)->setMeetingLatitude(31.6301)->setMeetingLongitude(-8.0089);
        $this->em->flush();

        return $trip;
    }

    private function mapData(): array
    {
        $map = $this->client->getCrawler()->filter('[data-controller~="trip-map"]');
        self::assertCount(1, $map, 'one trip map on the page');

        return json_decode((string) $map->attr('data-trip-map-data-value'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheTripPageMapShowsTheSearchedPortionWithoutExactPoints(): void
    {
        $trip = $this->tripWithMeetingPoints();

        $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));

        self::assertResponseIsSuccessful();
        $map = $this->mapData();
        self::assertSame('visitor', $map['viewer']);
        self::assertSame(['from' => 1, 'to' => 2], $map['highlight']);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('31.5126', $html, 'the exact meeting point is not in the page at all');
        self::assertSelectorNotExists('a[href*="google.com/maps"]');
    }

    public function testABookedPassengerGetsTheExactPointAndNavigationLinks(): void
    {
        $trip = $this->tripWithMeetingPoints();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $salma);
        $this->login($salma);

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(['lat' => 31.5126, 'lng' => -9.77], $this->mapData()['stops'][1]['exact']);
        self::assertSelectorExists('a[href="https://www.google.com/maps/dir/?api=1&destination=31.5126,-9.77"]');
        self::assertSelectorExists('a[href="https://waze.com/ul?ll=31.5126,-9.77&navigate=yes"]');
        self::assertStringContainsString('Ouvrir dans Google Maps', $this->pageText());
        self::assertStringContainsString('Ouvrir dans Waze', $this->pageText());
    }

    public function testNoNavigationLinkWhenTheDriverDidNotPlaceThePoint(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $salma);
        $this->login($salma);

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="google.com/maps"]');
    }

    public function testTheDriverMapShowsHowFullEachLegIs(): void
    {
        $trip = $this->tripWithMeetingPoints();
        $this->db->booking($trip, 1, 2, 2);
        $this->login($trip->getDriver());

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([0, 2, 0], array_column($this->mapData()['legs'], 'seatsTaken'));
    }

    public function testATripWithoutSavedRouteStillHasAMap(): void
    {
        $trip = $this->db->trip(); // no geometry saved: the map draws straight lines

        $this->client->request('GET', sprintf('/trips/%d', $trip->getId()));

        self::assertResponseIsSuccessful();
        self::assertNull($this->mapData()['legs'][0]['geometry']);
    }

    public function testThePublishPageHasAMapAndHiddenCoordinateFields(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->vehicle($karim);
        $this->login($karim);

        $this->client->request('GET', '/trips/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="publish-map"]');
        self::assertSelectorExists('input[type="hidden"][name="trip[stops][0][meetingLatitude]"]');
        self::assertSelectorExists('input[type="hidden"][name="trip[stops][0][meetingLongitude]"]');
    }

    public function testPublishingSavesTheMeetingPointsAndTheRoute(): void
    {
        $trip = $this->publishAgadirEssaouira();

        $first = DbTrips::stop($trip, 0);
        self::assertSame(30.4202, $first->getMeetingLatitude());
        self::assertSame(-9.5982, $first->getMeetingLongitude());
        self::assertNull(DbTrips::stop($trip, 1)->getMeetingLatitude(), 'not placed on the map: stays empty');
        self::assertGreaterThan(0, $first->getLegDistanceKmToNext());
        self::assertSame('fake-polyline', $first->getLegGeometry());
    }

    public function testPublishingWorksWhenTheMapServicesAreDown(): void
    {
        FakeGeoApis::$down = true;

        $trip = $this->publishAgadirEssaouira();

        self::assertNull(DbTrips::stop($trip, 0)->getLegDistanceKmToNext());
        self::assertSame(30.4202, DbTrips::stop($trip, 0)->getMeetingLatitude(), 'the point placed by the driver is kept');
    }

    public function testCoordinatesOutsideTheEarthAreAFormError(): void
    {
        $karim = $this->db->user('Karim');
        $this->postTrip($karim, ['meetingLatitude' => '123', 'meetingLongitude' => '-9.5982']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]));
    }

    private function publishAgadirEssaouira(): Trip
    {
        $karim = $this->db->user('Karim');
        $this->postTrip($karim, ['meetingLatitude' => '30.4202', 'meetingLongitude' => '-9.5982']);

        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]);
        self::assertNotNull($trip, 'the trip is saved');
        self::assertResponseRedirects(sprintf('/driver/trips/%d', $trip->getId()));

        return $trip;
    }

    /** @param array<string, string> $firstStopCoordinates */
    private function postTrip(User $driver, array $firstStopCoordinates): void
    {
        $vehicle = $this->db->vehicle($driver);
        $agadir = $this->db->city('Agadir');
        $essaouira = $this->db->city('Essaouira');
        $this->login($driver);

        $crawler = $this->client->request('GET', '/trips/new');
        $form = $crawler->selectButton('Publier le trajet')->form();
        $values = $form->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        $values['trip']['stops'] = [
            ['city' => $agadir->getId(), 'departureAt' => '2026-11-12T08:00', 'meetingPoint' => 'Gare routière', 'legPriceToNext' => 60] + $firstStopCoordinates,
            ['city' => $essaouira->getId(), 'departureAt' => '2026-11-12T10:15', 'meetingPoint' => 'Bab Marrakech', 'legPriceToNext' => '', 'meetingLatitude' => '', 'meetingLongitude' => ''],
        ];
        $this->client->request('POST', $form->getUri(), $values);
    }
}
