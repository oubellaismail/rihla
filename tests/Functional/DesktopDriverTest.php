<?php

namespace App\Tests\Functional;

use App\Entity\Trip;

/**
 * Driver side of the desktop review (docs/UX-REVIEW-DESKTOP.md, fixes 3, 5, 6, 7, 9).
 * "Now" is 2026-11-10 10:00. Default trip on 2026-11-12: Agadir 08:00 → Essaouira 09:45
 * → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats.
 */
final class DesktopDriverTest extends WebFlowTestCase
{
    /** Agadir → Essaouira full, Essaouira → Marrakech 1 seat left, Marrakech → Casablanca full; 6 passengers. */
    private function busyTrip(): Trip
    {
        $trip = $this->db->trip(driver: $this->db->user('Karim'));
        $this->db->booking($trip, 0, 1, 2);
        $this->db->booking($trip, 0, 3, 1);
        $this->db->booking($trip, 1, 2, 1);
        $this->db->booking($trip, 2, 3, 2);
        $this->login($trip->getDriver());

        return $trip;
    }

    // ---- fix 6: the per-portion story on the driver side -----------------------------------------

    public function testTheDriverListSaysWhereASeatIsLeft(): void
    {
        $this->busyTrip();

        $this->client->request('GET', '/driver/trips');

        self::assertStringContainsString('Complet sauf Essaouira → Marrakech (1 place)', $this->pageText());
        self::assertStringNotContainsString('3 / 3 places réservées', $this->pageText());
    }

    public function testFreeLegsInARowAreNamedAsOnePortion(): void
    {
        $trip = $this->db->trip(driver: $this->db->user('Karim'));
        $this->db->booking($trip, 0, 1, 3);
        $this->db->booking($trip, 2, 3, 1);
        $this->login($trip->getDriver());

        $this->client->request('GET', '/driver/trips');

        self::assertStringContainsString('Complet sauf Essaouira → Casablanca (2 places)', $this->pageText(), 'the run keeps its smallest count');
    }

    public function testATripWithNoFullLegKeepsItsCount(): void
    {
        $trip = $this->db->trip(driver: $this->db->user('Karim'));
        $this->db->booking($trip, 1, 2, 1);
        $this->login($trip->getDriver());

        $this->client->request('GET', '/driver/trips');

        self::assertStringContainsString('1 / 3 places réservées', $this->pageText());
    }

    public function testTheDriverTripPageSummarisesThePassengersAndTheSeatLeft(): void
    {
        $trip = $this->busyTrip();

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertSelectorTextSame('.driver-summary', '6 passagers · 1 place libre sur Essaouira → Marrakech');
    }

    public function testPassengersComeBeforeTheMap(): void
    {
        $trip = $this->busyTrip();

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'Carte du trajet'), strpos($html, 'Arrêts et passagers'));
    }

    public function testTheDriverTimelineCarriesSeatsAndPricePerLeg(): void
    {
        $trip = $this->busyTrip();

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertSame(
            ['Complet · 60 MAD', '1 place libre · 65 MAD', 'Complet · 85 MAD'],
            $crawler->filter('.timeline .leg-pill')->each(static fn ($n) => trim(preg_replace('/\s+/', ' ', $n->text()))),
        );
    }

    public function testTheDriverPageTitleUsesTheDrawnRoute(): void
    {
        $trip = $this->busyTrip();

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertSelectorExists('h1 .route svg');
        self::assertStringNotContainsString('→', $this->client->getCrawler()->filter('h1')->text());
    }

    // ---- fix 5: cancelling a whole trip asks first -----------------------------------------------

    public function testCancellingTheTripAsksFirstAndSaysWhoIsRefunded(): void
    {
        $trip = $this->busyTrip();

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        $onsubmit = (string) $crawler->filter(sprintf('form[action="/driver/trips/%d/cancel"]', $trip->getId()))->attr('onsubmit');
        self::assertStringContainsString('confirm(', $onsubmit);
        self::assertStringContainsString('Annuler ce trajet ?', $onsubmit);
        self::assertStringContainsString('6 passagers seront remboursés à 100 %', $onsubmit);
    }

    // ---- fix 7: publishing says the price is suggested -------------------------------------------

    public function testThePublishFormSaysRihlaSuggestsThePrice(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->vehicle($karim);
        $this->login($karim);

        $this->client->request('GET', '/trips/new');

        self::assertStringContainsString('Rihla calcule la distance par la route et vous propose un prix par tronçon', $this->pageText());
        self::assertSelectorExists('input[name$="[legPriceToNext]"][placeholder="Prix conseillé après le choix des villes"]');
    }
}
