<?php

namespace App\Tests\Functional;

/**
 * The landing page: route chips and the next departure in the hero, live route tiles below.
 * "Now" is 2026-11-10 10:00. Default trip on 2026-11-12:
 * Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats.
 */
final class LandingPageTest extends WebFlowTestCase
{
    public function testTheHeroOffersRouteChipsInsteadOfAnExample(): void
    {
        foreach (['Essaouira', 'Marrakech', 'Casablanca', 'Rabat', 'Agadir'] as $name) {
            $this->db->city($name);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        $chips = $crawler->filter('.hero .quick-route')->each(static fn ($chip) => $chip->text());
        self::assertSame(['Essaouira → Marrakech', 'Casablanca → Rabat', 'Agadir → Marrakech'], $chips);
        self::assertSelectorNotExists('.float-note');
    }

    public function testTheHeroShowsTheNextTripWithAFreeSeat(): void
    {
        $trip = $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', '/');

        self::assertSelectorExists(sprintf('.hero a.next-departure[href="/trips/%d"]', $trip->getId()));
        $strip = $this->client->getCrawler()->filter('.next-departure')->text();
        self::assertStringContainsString('Prochain départ', $strip);
        self::assertStringContainsString('Agadir → Casablanca', $strip);
        self::assertStringContainsString('3 places', $strip);
        self::assertStringContainsString('dès 60 MAD', $strip);
    }

    public function testAFullTripIsNotTheNextDeparture(): void
    {
        $trip = $this->db->trip(seatsOffered: 1);
        $this->db->booking($trip, 0, 3, passenger: $this->db->user('Rachid'));
        $this->em->flush();

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.next-departure');
    }

    public function testARouteTileCountsDeparturesAndShowsTheLowestPrice(): void
    {
        $this->db->trip();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        $tile = $crawler->filter('.route-tile')->reduce(static fn ($t) => str_contains($t->text(), 'Agadir → Marrakech'));
        self::assertCount(1, $tile);
        self::assertStringContainsString('1 départ', $tile->text());
        self::assertStringContainsString('dès 125 MAD', $tile->text());
        self::assertStringContainsString('/trips?from=Agadir&to=Marrakech', (string) $tile->attr('href'));
    }

    public function testEmptyRoutesOnlyFillOneRowAndInviteDriversToPublish(): void
    {
        foreach (['Casablanca', 'Marrakech', 'Agadir', 'Essaouira', 'Rabat', 'Tanger'] as $name) {
            $this->db->city($name);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        $empty = $crawler->filter('.route-tile--empty');
        self::assertLessThanOrEqual(4, $empty->count());
        self::assertGreaterThan(0, $empty->count());
        self::assertSame('/trips/new', $empty->first()->attr('href'));
        self::assertStringContainsString('Aucun départ prévu', $empty->first()->text());
    }
}
