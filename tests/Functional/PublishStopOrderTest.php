<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\TripStop;

/**
 * Stops added with « Ajouter un arrêt » get the next free number (stops[2], stops[3]…) but are
 * inserted before the arrival (stops[1]). The browser sends the fields in page order, so the
 * trip must keep the order of the page, not the numbers.
 */
final class PublishStopOrderTest extends WebFlowTestCase
{
    public function testStopsKeepTheOrderOfThePageNotTheirNumbers(): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim);
        $this->login($karim);
        $crawler = $this->client->request('GET', '/trips/new');
        $values = $crawler->selectButton('Publier le trajet')->form()->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        foreach (['Casablanca', 'El Jadida', 'Essaouira', 'Agadir'] as $name) {
            $this->db->city($name);
        }
        $this->em->flush(); // new cities need an id before the form refers to them
        $stop = fn (string $city, string $at, int|string $price) => ['city' => $this->db->city($city)->getId(), 'departureAt' => $at, 'meetingPoint' => 'Centre-ville', 'legPriceToNext' => $price];
        $values['trip']['stops'] = [
            0 => $stop('Casablanca', '2026-11-12T08:00', 35),
            2 => $stop('El Jadida', '2026-11-12T09:15', 95),
            3 => $stop('Essaouira', '2026-11-12T13:00', 60),
            1 => $stop('Agadir', '2026-11-12T15:30', ''),
        ];

        $this->client->request('POST', '/trips/new', $values);

        self::assertResponseRedirects();
        $this->em->clear();
        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim->getId()]);
        self::assertSame(
            ['Casablanca', 'El Jadida', 'Essaouira', 'Agadir'],
            $trip->getStops()->map(static fn (TripStop $s) => $s->getCity()->getName())->getValues(),
        );
        self::assertSame(35 + 95 + 60, $trip->getStops()->map(static fn (TripStop $s) => $s->getLegPriceToNext() ?? 0)->reduce(static fn ($a, $b) => $a + $b, 0));
    }
}
