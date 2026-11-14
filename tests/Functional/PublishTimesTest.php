<?php

namespace App\Tests\Functional;

/**
 * Publishing: each time field says which time it is (docs/DECISIONS.md, « times filled in
 * for the driver »). Filling in the empty times is JavaScript (publish_map_controller.js),
 * checked in the browser.
 */
final class PublishTimesTest extends WebFlowTestCase
{
    private function driver(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->vehicle($karim);
        $this->login($karim);
    }

    public function testANewTripLabelsTheDepartureAndTheArrival(): void
    {
        $this->driver();

        $this->client->request('GET', '/trips/new');

        self::assertSelectorTextSame('label[for="trip_stops_0_departureAt"]', 'Date et heure de départ');
        self::assertSelectorTextSame('label[for="trip_stops_1_departureAt"]', 'Arrivée prévue');
        self::assertSame(['Départ', 'Arrivée'], $this->client->getCrawler()->filter('.stop-label')->each(static fn ($n) => $n->text()));
    }

    public function testAMiddleStopLabelsItsDeparture(): void
    {
        $this->driver();
        $crawler = $this->client->request('GET', '/trips/new');
        $values = $crawler->selectButton('Publier le trajet')->form()->getPhpValues();
        $values['trip']['stops'] = [
            ['city' => $this->db->city('Agadir')->getId(), 'departureAt' => '2026-11-12T08:00', 'meetingPoint' => 'Gare', 'legPriceToNext' => 60],
            ['city' => $this->db->city('Essaouira')->getId(), 'departureAt' => '2026-11-12T07:00', 'meetingPoint' => 'Bab', 'legPriceToNext' => 65],
            ['city' => $this->db->city('Marrakech')->getId(), 'departureAt' => '2026-11-12T12:00', 'meetingPoint' => 'Gare', 'legPriceToNext' => ''],
        ];

        $this->client->request('POST', '/trips/new', $values); // refused: the form comes back with three stops

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('label[for="trip_stops_1_departureAt"]', 'Départ de cet arrêt');
        self::assertSelectorTextSame('label[for="trip_stops_2_departureAt"]', 'Arrivée prévue');
        self::assertSame(['Départ', 'Arrêt 1', 'Arrivée'], $this->client->getCrawler()->filter('.stop-label')->each(static fn ($n) => $n->text()));
    }
}
