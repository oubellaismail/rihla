<?php

namespace App\Tests\Functional;

use App\Entity\Review;
use App\Entity\User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * « Prochains départs » (/trips with no search) uses the same cards, filters and sort as a search.
 * "Now" is 2026-11-10 10:00. Default trip on 2026-11-12, Dacia Logan bleue, 530 L trunk:
 * Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats.
 *
 * With no portion chosen, a card draws the whole trip (every stop) and its price is « dès » the cheapest
 * leg that still has a seat; maxPrice and the price sort use that same price.
 */
final class UpcomingListTest extends WebFlowTestCase
{
    private function list(string $query = ''): Crawler
    {
        $this->em->flush();

        return $this->client->request('GET', '/trips'.('' === $query ? '' : '?'.ltrim($query, '&')));
    }

    /** @return list<string> departure times of the cards, in page order */
    private function times(Crawler $crawler): array
    {
        return $crawler->filter('.result .result-stop--boarding .result-stop__time')->each(static fn (Crawler $n): string => trim($n->text()));
    }

    private function rate(User $driver, int $rating): void
    {
        $trip = $this->db->trip(driver: $driver, day: '2026-11-01');
        $booking = $this->db->booking($trip, 0, 1, passenger: $this->db->user('Rachid'));
        $this->em->persist((new Review())->setBooking($booking)->setAuthor($booking->getPassenger())->setTarget($driver)->setRating($rating));
    }

    public function testTheCardsAreTheSearchCardsWithTheWholeRoute(): void
    {
        $trip = $this->db->trip();

        $crawler = $this->list();

        self::assertSelectorNotExists('.upcoming', 'the old one-line rows are gone');
        $card = $crawler->filter('.upcoming-day .result')->first();
        self::assertSame(sprintf('/trips/%d', $trip->getId()), $card->attr('href'));
        self::assertSame('08:00', trim($card->filter('.result-stop--boarding .result-stop__time')->text()));
        self::assertStringContainsString('Agadir', $card->filter('.result-stop--boarding')->text());
        self::assertStringContainsString('Centre-ville', $card->filter('.result-stop--boarding')->text(), 'the meeting point');
        self::assertSame('15:00', trim($card->filter('.result-stop--drop-off .result-stop__time')->text()));
        self::assertStringContainsString('Casablanca', $card->filter('.result-stop--drop-off')->text());
        self::assertSame('7 h', trim($card->filter('.result-duration')->text()));
        self::assertSame(
            ['09:45 Essaouira', '12:00 Marrakech'],
            $card->filter('.result-stop--via')->each(static fn (Crawler $n): string => preg_replace('/\s+/', ' ', trim($n->text()))),
            'every stop in between, so a portion can be spotted',
        );
        self::assertCount(0, $card->filter('.result-stop--before, .result-stop--after'));
        self::assertStringContainsString('Dacia Logan bleue', $card->filter('.result-driver')->text());
    }

    public function testThePriceIsFromTheCheapestLegWithASeat(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 3); // the 60 MAD leg is full

        $card = $this->list()->filter('.result')->first();

        self::assertStringContainsString('dès 65 MAD', preg_replace('/\s+/', ' ', $card->filter('.result-price')->text()));
    }

    public function testATripFullOnlyOnSomeLegsSaysSo(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 3);

        $card = $this->list()->filter('.result')->first();

        self::assertStringContainsString('Places sur une partie du trajet', $card->filter('.result-price')->text());
    }

    public function testTheListKeepsItsDayGroups(): void
    {
        $this->db->trip(stops: [['Marrakech', '18:00', 90], ['Casablanca', '21:00', null]], day: '2026-11-10');
        $this->db->trip(day: '2026-11-11');

        $crawler = $this->list();

        self::assertSame(["Aujourd'hui", 'Demain'], $crawler->filter('.upcoming-day__title')->each(static fn (Crawler $n): string => $n->text()));
        self::assertCount(1, $crawler->filter('.upcoming-day')->eq(0)->filter('.result'));
    }

    public function testTheFiltersAndSortAreThere(): void
    {
        $this->db->trip();

        $this->list();

        self::assertSelectorExists('form.search-filters[method="get"][action="/trips"][data-controller~="search-filters"]');
        self::assertSelectorNotExists('form.search-filters input[type="hidden"][name="from"]');
        self::assertSelectorExists('form.search-filters input[name="time[]"][value="morning"]');
        self::assertSelectorExists('form.search-filters input[name="maxPrice"]');
        self::assertSelectorExists('form.search-filters input[name="bag"][value="large"]');
        self::assertSelectorExists('form.search-filters input[name="rated"]');
        self::assertSelectorExists('.search-sort a[href="/trips?sort=price"]');
        self::assertSelectorExists('.search-sort a[aria-current="true"][href="/trips"]');
    }

    public function testTimeOfDayFiltersOnTheDepartureOfTheCar(): void
    {
        $this->db->trip(stops: [['Fès', '09:00', 80], ['Tanger', '13:00', null]]);
        $this->db->trip(stops: [['Agadir', '19:00', 40], ['Tiznit', '20:30', null]]);

        self::assertSame(['19:00'], $this->times($this->list('time[]=evening')));
        self::assertSelectorExists('form.search-filters input[name="time[]"][value="evening"][checked]');
    }

    public function testMaxPriceKeepsTripsWithALegAtThatPrice(): void
    {
        $this->db->trip(stops: [['Fès', '09:00', 80], ['Tanger', '13:00', null]]);
        $this->db->trip(stops: [['Agadir', '10:00', 30], ['Taroudant', '11:00', 120], ['Ouarzazate', '15:00', null]]);

        self::assertSame(['10:00'], $this->times($this->list('maxPrice=50')), 'its 30 MAD leg fits, the whole trip does not matter');
    }

    public function testSortByPriceUsesTheFromPriceAndDropsTheDays(): void
    {
        $this->db->trip(stops: [['Fès', '09:00', 80], ['Tanger', '13:00', null]], day: '2026-11-11');
        $this->db->trip(stops: [['Agadir', '10:00', 30], ['Taroudant', '11:00', 120], ['Ouarzazate', '15:00', null]], day: '2026-11-12');

        $crawler = $this->list('sort=price');

        self::assertSame(['10:00', '09:00'], $this->times($crawler));
        self::assertCount(0, $crawler->filter('.upcoming-day'), 'days only make sense in departure order');
    }

    public function testRatedKeepsDriversRatedFourOrMore(): void
    {
        $good = $this->db->user('Hamid');
        $this->db->trip(driver: $good, stops: [['Fès', '09:00', 80], ['Tanger', '13:00', null]]);
        $this->db->trip(driver: $this->db->user('Nadia'), stops: [['Agadir', '10:00', 40], ['Tiznit', '11:30', null]]);
        $this->rate($good, 5);

        self::assertSame(['09:00'], $this->times($this->list('rated=1')));
    }

    public function testNoResultAfterFilteringOffersToClearThem(): void
    {
        $this->db->trip();

        $this->list('maxPrice=10');

        self::assertStringContainsString('Aucun trajet ne correspond à vos filtres.', $this->pageText());
        self::assertSelectorExists('a.search-filters__clear[href="/trips"]');
        self::assertStringNotContainsString('Aucun départ annoncé', $this->pageText());
    }

    public function testWithNoTripTheFiltersAreNotShown(): void
    {
        $this->list();

        self::assertStringContainsString('Aucun départ annoncé pour le moment', $this->pageText());
        self::assertSelectorNotExists('form.search-filters');
    }
}
