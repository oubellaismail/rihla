<?php

namespace App\Tests\Functional;

/**
 * The trip page and the seat page after the UX review (docs/UX-REVIEW.md, fixes 4 and 6).
 * "Now" is 2026-11-10 10:00. Default trip on 2026-11-12: Agadir 08:00 → Essaouira 09:45
 * → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD (210 MAD in all), 3 seats.
 * The sticky bars themselves are CSS; here we check what they contain and where things sit.
 */
final class TripAndSeatPagesTest extends WebFlowTestCase
{
    private function tripPage(int $from, int $to): \Symfony\Component\DomCrawler\Crawler
    {
        $trip = $this->db->trip();
        $this->login($this->db->user('Salma'));

        return $this->client->request('GET', sprintf('/trips/%d?from=%d&to=%d', $trip->getId(), $from, $to));
    }

    // ---- fix 4: the trip page --------------------------------------------------------------------

    public function testTheTitleIsThePassengersPortion(): void
    {
        $this->tripPage(1, 2);

        self::assertSelectorTextContains('h1', 'Essaouira');
        self::assertSelectorTextContains('h1', 'Marrakech');
        self::assertStringNotContainsString('Agadir', $this->client->getCrawler()->filter('h1')->text(), 'the driver goes further; that is said below the title');
        self::assertSelectorTextContains('.trip-head', 'Agadir → Casablanca');
    }

    public function testThePriceOfAPortionIsComparedToTheWholeTrip(): void
    {
        $this->tripPage(1, 2);

        self::assertSelectorTextContains('.price-box', 'au lieu de 210 MAD pour le trajet complet');
    }

    public function testNoComparisonWhenTheWholeTripIsChosen(): void
    {
        $this->tripPage(0, 3);

        self::assertStringNotContainsString('au lieu de', $this->pageText());
    }

    public function testABookingBarCarriesThePriceAndTheButton(): void
    {
        $crawler = $this->tripPage(1, 2);

        self::assertSelectorTextContains('.booking-bar', '65 MAD');
        self::assertSelectorTextContains('.booking-bar', 'Essaouira → Marrakech');
        $button = $crawler->filter('.booking-bar button[type="submit"]');
        self::assertSame('Choisir ma place', trim($button->text()));
        $formId = $button->attr('form');
        self::assertNotEmpty($formId, 'the bar submits the price-box form, with the seat count chosen there');
        self::assertSelectorExists(sprintf('.price-box form#%s select[name="seats"]', $formId));
    }

    public function testThePriceBoxButtonSaysWhatComesNext(): void
    {
        $crawler = $this->tripPage(1, 2);

        self::assertSame('Choisir ma place', trim($crawler->filter('.price-box button[type="submit"]')->text()), 'the next page is the seat choice; « Réserver » is kept for the last step');
    }

    public function testNoBookingBarWhenThePortionCannotBeBooked(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 1, 2, 3);
        $this->login($this->db->user('Salma'));

        $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));
        self::assertSelectorNotExists('.booking-bar');

        $this->login($trip->getDriver());
        $this->client->request('GET', sprintf('/trips/%d?from=0&to=1', $trip->getId()));
        self::assertSelectorNotExists('.booking-bar', 'not for the driver');
    }

    // ---- fix 6: the seat page --------------------------------------------------------------------

    public function testLettingRihlaChooseIsOfferedAboveTheCar(): void
    {
        $trip = $this->db->trip();
        $this->login($this->db->user('Salma'));

        $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=1', $trip->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        $auto = strpos($html, 'Pas de préférence, choisir pour moi');
        $plan = strpos($html, 'class="seats-plan');
        self::assertNotFalse($auto);
        self::assertNotFalse($plan);
        self::assertLessThan($plan, $auto, 'a passenger who does not care finishes in one tap, without scrolling past the car');
        self::assertCount(1, $this->client->getCrawler()->filter('button[name="auto"]'), 'offered once');
    }
}
