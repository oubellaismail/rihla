<?php

namespace App\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;

/**
 * One account, two spaces (like inDrive): a « Passager | Conducteur » switch in the one-row header, whose links
 * depend on the space. « Publier un trajet » stays in the header in both spaces; « Mes véhicules » is in the account menu.
 * "Now" is 2026-11-10 10:00.
 *
 * - Passenger space: /trips (Rechercher) and /bookings (Mes réservations).
 * - Driver space: /driver (Tableau de bord), /driver/trips (Mes trajets publiés), /driver/earnings (Mes gains),
 *   and its other pages /account/vehicles and /trips/new.
 * - Pages of a space switch to it; other pages (home, profile, account) keep the last space; a new session starts as passenger.
 */
final class SpacesTest extends WebFlowTestCase
{
    /** @return list<string> hrefs of the links of the current space, in the header */
    private function menu(Crawler $crawler): array
    {
        return $crawler->filter('.site-header .space-links a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
    }

    private function currentSpace(Crawler $crawler): string
    {
        return trim($crawler->filter('.space-switch a[aria-current="true"]')->text());
    }

    public function testAVisitorHasNoSwitch(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.space-switch');
        self::assertSelectorNotExists('.space-links');
    }

    public function testASignedInUserStartsAsPassenger(): void
    {
        $this->login($this->db->user('Salma'));

        $crawler = $this->client->request('GET', '/');

        self::assertSame('Passager', $this->currentSpace($crawler));
        self::assertSame(['/trips', '/bookings'], $this->menu($crawler));
        self::assertSelectorExists('.space-switch a[href="/trips"]');
        self::assertSelectorExists('.space-switch a[href="/driver"]');
        self::assertSelectorExists('.site-header a.btn[href="/trips/new"]', 'publishing stays one click away');
        self::assertSelectorExists('.site-header .menu-panel a[href="/account/vehicles"]');
        self::assertSelectorNotExists('nav.space-nav', 'one header row, no second bar');
    }

    public function testDriverPagesShowTheDriverMenu(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->vehicle($karim);
        $this->login($karim);

        $crawler = $this->client->request('GET', '/driver/trips');

        self::assertSame('Conducteur', $this->currentSpace($crawler));
        self::assertSame(['/driver', '/driver/trips', '/driver/earnings'], $this->menu($crawler));
        self::assertSelectorExists('.site-header .space-links a[href="/driver/trips"][aria-current="page"]');
        self::assertSelectorExists('.site-header a.btn[href="/trips/new"]');
    }

    public function testTheSpaceStaysOnPagesThatBelongToNeither(): void
    {
        $this->login($this->db->user('Karim'));

        $this->client->request('GET', '/driver/earnings');
        self::assertSame('Conducteur', $this->currentSpace($this->client->request('GET', '/')));

        $this->client->request('GET', '/bookings');
        self::assertSame('Passager', $this->currentSpace($this->client->request('GET', '/')));
    }

    public function testThePagesAreNamedAfterTheirSpace(): void
    {
        $this->login($this->db->user('Karim'));

        $this->client->request('GET', '/bookings');
        self::assertSelectorTextContains('h1', 'Mes réservations');
        self::assertSelectorNotExists('nav.tabs');

        $this->client->request('GET', '/driver/trips');
        self::assertSelectorTextContains('h1', 'Mes trajets publiés');
        self::assertSelectorNotExists('nav.tabs');
    }

    // ---- the driver dashboard -----------------------------------------------------------------

    public function testAVisitorIsSentToLogInFromTheDashboard(): void
    {
        $this->client->request('GET', '/driver');

        self::assertResponseRedirects('/login');
    }

    public function testSomeoneWithoutACarIsInvitedToBecomeADriver(): void
    {
        $this->login($this->db->user('Salma'));

        $crawler = $this->client->request('GET', '/driver');

        self::assertResponseIsSuccessful();
        self::assertSame('Conducteur', $this->currentSpace($crawler));
        self::assertStringContainsString('Devenir conducteur', $this->pageText());
        self::assertSelectorExists('main a[href="/account/vehicles"]');
    }

    public function testTheDashboardShowsTheNextTripAndTheMoney(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->vehicle($karim);
        $trip = $this->db->trip(driver: $karim);
        $this->db->booking($trip, 0, 2, 2, passenger: $this->db->user('Rachid'));
        $this->login($karim);

        $this->client->request('GET', '/driver');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorExists(sprintf('.dashboard-next a[href="/driver/trips/%d"]', $trip->getId()));
        self::assertSelectorTextContains('.dashboard-next', 'Agadir');
        self::assertSelectorTextContains('.dashboard-next', '2 places réservées');
        self::assertSelectorTextContains('.dashboard-kpi--upcoming', '200 MAD');
        self::assertSelectorExists('main a[href="/driver/earnings"]');
    }

    public function testADriverWithoutUpcomingTripsIsInvitedToPublish(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->vehicle($karim);
        $this->login($karim);

        $this->client->request('GET', '/driver');

        self::assertStringContainsString('Aucun trajet à venir', $this->pageText());
        self::assertSelectorExists('main a[href="/trips/new"]');
    }
}
