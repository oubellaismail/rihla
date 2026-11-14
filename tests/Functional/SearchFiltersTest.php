<?php

namespace App\Tests\Functional;

use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The search page: result cards draw the route as a timeline, and the results can be filtered and sorted.
 * "Now" is 2026-11-10 10:00. Default trip on 2026-11-12, Dacia Logan bleue, 530 L trunk:
 * Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats.
 *
 * Query parameters: time[] = morning (before 12:00) | afternoon (12:00–17:59) | evening (18:00 and later),
 * judged on the boarding time; maxPrice = MAD for one seat on the portion; bag = medium | large (one more such
 * bag still fits on the portion); rated = 1 (driver average 4 or more, drivers without reviews left out);
 * sort = departure (default) | price | rating.
 */
final class SearchFiltersTest extends WebFlowTestCase
{
    /** A trip leaving Essaouira at $time for Marrakech, two hours later, at $price MAD. */
    private function essaouiraMarrakech(string $time, int $price, ?User $driver = null, int $trunkLitres = 530): Trip
    {
        $arrival = (new \DateTimeImmutable('2026-11-12 '.$time))->modify('+2 hours')->format('H:i');

        return $this->db->trip(driver: $driver, stops: [['Essaouira', $time, $price], ['Marrakech', $arrival, null]], trunkLitres: $trunkLitres);
    }

    private function rate(User $driver, int $rating): void
    {
        $trip = $this->db->trip(driver: $driver, day: '2026-11-01');
        $booking = $this->db->booking($trip, 0, 1, passenger: $this->db->user('Rachid'));
        $this->em->persist((new Review())->setBooking($booking)->setAuthor($booking->getPassenger())->setTarget($driver)->setRating($rating));
        $this->em->flush();
    }

    private function search(string $query): Crawler
    {
        $this->em->flush();

        return $this->client->request('GET', '/trips?from=Essaouira&to=Marrakech&date=2026-11-12'.$query);
    }

    /** @return list<string> boarding times of the result cards, in page order */
    private function times(Crawler $crawler): array
    {
        return $crawler->filter('.result .result-stop--boarding .result-stop__time')->each(static fn (Crawler $n): string => trim($n->text()));
    }

    // ---- the route as a timeline ----------------------------------------------------------------

    public function testTheCardDrawsThePortionAsATimeline(): void
    {
        $this->db->trip();

        $crawler = $this->search('');

        $card = $crawler->filter('.result')->first();
        $boarding = $card->filter('.result-timeline .result-stop--boarding');
        self::assertSame('09:45', trim($boarding->filter('.result-stop__time')->text()));
        self::assertStringContainsString('Essaouira', $boarding->text());
        self::assertStringContainsString('Centre-ville', $boarding->text(), 'the meeting point');
        $dropOff = $card->filter('.result-timeline .result-stop--drop-off');
        self::assertSame('12:00', trim($dropOff->filter('.result-stop__time')->text()));
        self::assertStringContainsString('Marrakech', $dropOff->text());
        self::assertSame('2 h 15', trim($card->filter('.result-duration')->text()));
    }

    public function testTheCardShowsWhereTheCarComesFromAndWhereItGoesOn(): void
    {
        $this->db->trip();

        $card = $this->search('')->filter('.result')->first();

        $before = $card->filter('.result-stop--before');
        self::assertCount(1, $before);
        self::assertStringContainsString('08:00', $before->text());
        self::assertStringContainsString('Agadir', $before->text());
        self::assertStringContainsString('Départ de la voiture', $before->text());
        $after = $card->filter('.result-stop--after');
        self::assertCount(1, $after);
        self::assertStringContainsString('15:00', $after->text());
        self::assertStringContainsString('Casablanca', $after->text());
        self::assertStringContainsString('Terminus', $after->text());
    }

    public function testAWholeTripHasNoFadedStops(): void
    {
        $this->essaouiraMarrakech('09:00', 70);

        $card = $this->search('')->filter('.result')->first();

        self::assertCount(0, $card->filter('.result-stop--before, .result-stop--after'));
        self::assertSame('2 h', trim($card->filter('.result-duration')->text()));
    }

    public function testTheCardNamesTheDriversCar(): void
    {
        $this->db->trip();

        $card = $this->search('')->filter('.result')->first();

        self::assertStringContainsString('Dacia Logan bleue', $card->filter('.result-driver')->text());
    }

    // ---- filters ----------------------------------------------------------------------------------

    public function testTheFiltersFormKeepsTheSearchAndAppliesOnChange(): void
    {
        $this->db->trip();

        $this->search('&seats=2');

        self::assertSelectorExists('form.search-filters[method="get"][action="/trips"][data-controller~="search-filters"]');
        foreach (['from' => 'Essaouira', 'to' => 'Marrakech', 'date' => '2026-11-12', 'seats' => '2'] as $name => $value) {
            self::assertSelectorExists(sprintf('form.search-filters input[type="hidden"][name="%s"][value="%s"]', $name, $value));
        }
        self::assertSelectorExists('form.search-filters[data-action*="change->search-filters#apply"]');
    }

    public function testTimeOfDayKeepsOnlyTheChosenSlots(): void
    {
        $this->essaouiraMarrakech('09:00', 70);
        $this->essaouiraMarrakech('14:00', 70);
        $this->essaouiraMarrakech('19:00', 70);

        self::assertSame(['09:00'], $this->times($this->search('&time[]=morning')));
        self::assertSame(['14:00', '19:00'], $this->times($this->search('&time[]=afternoon&time[]=evening')));
        self::assertSelectorExists('form.search-filters input[type="checkbox"][name="time[]"][value="afternoon"][checked]');
        self::assertSelectorExists('form.search-filters input[type="checkbox"][name="time[]"][value="morning"]:not([checked])');
    }

    public function testMaxPriceDropsDearerTrips(): void
    {
        $this->essaouiraMarrakech('09:00', 70);
        $this->essaouiraMarrakech('10:00', 120);

        $crawler = $this->search('&maxPrice=100');

        self::assertSame(['09:00'], $this->times($crawler));
        self::assertSelectorExists('form.search-filters input[name="maxPrice"][value="100"]');
    }

    public function testBagKeepsTripsWhereThatSuitcaseStillFits(): void
    {
        $this->essaouiraMarrakech('09:00', 70, trunkLitres: 530);
        $this->essaouiraMarrakech('10:00', 70, trunkLitres: 50);

        self::assertSame(['09:00'], $this->times($this->search('&bag=large')));
        self::assertSame(['09:00', '10:00'], $this->times($this->search('')));
        self::assertSelectorExists('form.search-filters input[type="radio"][name="bag"][value=""]:checked, form.search-filters input[type="radio"][name="bag"][value=""][checked]');
    }

    public function testRatedKeepsDriversRatedFourOrMore(): void
    {
        $good = $this->db->user('Hamid');
        $poor = $this->db->user('Youssef');
        $this->essaouiraMarrakech('09:00', 70, $good);
        $this->essaouiraMarrakech('10:00', 70, $poor);
        $this->essaouiraMarrakech('11:00', 70, $this->db->user('Nadia')); // no review yet
        $this->rate($good, 5);
        $this->rate($poor, 3);

        self::assertSame(['09:00'], $this->times($this->search('&rated=1')));
    }

    public function testNoResultAfterFilteringOffersToClearThem(): void
    {
        $this->essaouiraMarrakech('09:00', 70);

        $this->search('&maxPrice=10&seats=2');

        self::assertStringContainsString('Aucun trajet ne correspond à vos filtres.', $this->pageText());
        self::assertSelectorExists('a.search-filters__clear[href="/trips?from=Essaouira&to=Marrakech&date=2026-11-12&seats=2"]');
        self::assertStringNotContainsString('Aucun conducteur', $this->pageText(), 'trips exist, the filters hide them');
    }

    public function testTheHeadingCountsTheFilteredResults(): void
    {
        $this->essaouiraMarrakech('09:00', 70);
        $this->essaouiraMarrakech('14:00', 70);

        $this->search('&time[]=morning');

        self::assertSelectorTextContains('h1', '1 trajet');
    }

    // ---- sorting ----------------------------------------------------------------------------------

    public function testSortByPriceCheapestFirst(): void
    {
        $this->essaouiraMarrakech('09:00', 90);
        $this->essaouiraMarrakech('10:00', 50);
        $this->essaouiraMarrakech('11:00', 70);

        self::assertSame(['10:00', '11:00', '09:00'], $this->times($this->search('&sort=price')));
        self::assertSelectorExists('.search-sort a[aria-current="true"][href*="sort=price"]');
    }

    public function testSortByRatingBestFirstAndUnratedLast(): void
    {
        $good = $this->db->user('Hamid');
        $fair = $this->db->user('Youssef');
        $this->essaouiraMarrakech('09:00', 70, $this->db->user('Nadia'));
        $this->essaouiraMarrakech('10:00', 70, $fair);
        $this->essaouiraMarrakech('11:00', 70, $good);
        $this->rate($good, 5);
        $this->rate($fair, 4);

        self::assertSame(['11:00', '10:00', '09:00'], $this->times($this->search('&sort=rating')));
    }

    public function testSortLinksKeepTheFilters(): void
    {
        $this->essaouiraMarrakech('09:00', 70);

        $crawler = $this->search('&maxPrice=100&time[]=morning');

        $href = (string) $crawler->filter('.search-sort a[href*="sort=price"]')->attr('href');
        self::assertStringContainsString('maxPrice=100', $href);
        self::assertStringContainsString('morning', $href);
    }

    public function testWithoutADateASortOtherThanDepartureListsResultsWithoutDayGroups(): void
    {
        $this->db->trip(day: '2026-11-11');
        $this->db->trip(day: '2026-11-13');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/trips?from=Essaouira&to=Marrakech&sort=price');

        self::assertCount(2, $crawler->filter('.result'));
        self::assertCount(0, $crawler->filter('.upcoming-day'), 'days only make sense in departure order');
    }
}
