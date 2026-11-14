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

    public function testTheHeroHasTheEyebrowAndTheFullSearchBar(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.hero .hero__eyebrow', 'Covoiturage régional au Maroc');
        self::assertSelectorExists('.hero form.searchbar select[name="seats"]');
        self::assertSelectorExists('.hero form.searchbar button[type="button"][data-action*="swap"]', 'swaps departure and arrival');
    }

    public function testThePrincipleExplainsThePortionWithAWorkedExample(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertCount(3, $crawler->filter('#principe .principle-step'));
        self::assertSelectorExists('#principe .fare-demo[data-controller~="fare-demo"]');
        self::assertCount(4, $crawler->filter('#principe .fare-demo [data-fare-demo-target="stop"]'));
        self::assertSelectorTextContains('#principe .fare-demo', 'Essaouira → Marrakech');
        self::assertSelectorTextContains('#principe .fare-demo', 'Vous gardez 130 MAD en poche');
    }

    public function testPassengerAndDriverBandsLeadToTheirAction(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorExists('#passagers a[href="/trips"]');
        self::assertSelectorExists('#conducteurs a[href="/trips/new"]');
        self::assertSelectorTextContains('.luggage-band', 'Et vos bagages aussi.');
    }

    public function testTheRoutesEndWithAnInvitationToPublishWhenTheyAreFew(): void
    {
        $this->db->trip();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('#trajets .route-card:not(.route-card--empty)'));
        $empty = $crawler->filter('#trajets .route-card--empty');
        self::assertCount(1, $empty);
        self::assertStringContainsString('Casablanca → Marrakech', $empty->text());
        self::assertStringContainsString('Aucun départ prévu', $empty->text());
        self::assertSame('/trips/new', $empty->filter('a')->last()->attr('href') ?? $empty->attr('href'));
    }

    public function testTrustReviewsAndTheClosingCall(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertCount(4, $crawler->filter('#confiance .trust-item'));
        self::assertSelectorExists('.cta-banner a[href="/trips"]');
        self::assertSelectorExists('.cta-banner a[href="/trips/new"]');
        self::assertSelectorTextContains('.site-footer', 'Bonne route');
    }

    public function testARouteCardShowsThePhotoOfItsArrivalCity(): void
    {
        $this->db->trip(); // Agadir → Casablanca
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertStringContainsString('city-casablanca', (string) $crawler->filter('#trajets .route-card:not(.route-card--empty) img')->attr('src'));
    }

    public function testTheFooterCreditsAiGeneratedPhotos(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.site-footer', 'Photos générées par IA');
        self::assertSelectorTextNotContains('.site-footer', 'Unsplash');
    }
}
