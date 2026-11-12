<?php

namespace App\Tests\Functional;

/**
 * How the jury gets in (docs/UX-REVIEW-DESKTOP.md, fix 4): demo accounts on the login page
 * when the app runs in debug (dev, and test here), and a login message that matches what the
 * visitor was trying to do.
 */
final class WayInTest extends WebFlowTestCase
{
    public function testTheLoginPageOffersTheDemoAccounts(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertSelectorTextContains('.demo-accounts', 'Comptes de démo');
        foreach (['karim@rihla.ma', 'salma@rihla.ma', 'imane@rihla.ma'] as $email) {
            self::assertCount(1, $crawler->filter(sprintf('.demo-accounts button[type="button"][data-email="%s"]', $email)), $email);
        }
        self::assertStringContainsString('rihla2026', $crawler->filter('.demo-accounts')->text());
    }

    public function testPublishingAsksToLogInToPublish(): void
    {
        $this->client->request('GET', '/trips/new');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();

        self::assertStringContainsString('Connectez-vous pour publier un trajet.', $this->pageText());
        self::assertStringNotContainsString('réserver votre place', $this->pageText());
    }

    public function testOtherPrivatePagesAskNothingSpecial(): void
    {
        $this->client->request('GET', '/bookings');
        $this->client->followRedirect();

        self::assertStringNotContainsString('Connectez-vous pour', $this->pageText());
    }

    public function testSigningUpSaysTheBookingWillResume(): void
    {
        $trip = $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=1', $trip->getId()));
        $this->client->request('GET', '/register');

        self::assertStringContainsString('Votre réservation reprendra juste après.', $this->pageText());
    }

    public function testSigningUpWithoutABookingSaysNothingAboutIt(): void
    {
        $this->client->request('GET', '/register');

        self::assertStringNotContainsString('Votre réservation reprendra', $this->pageText());
    }
}
