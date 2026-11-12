<?php

namespace App\Tests\Functional;

/**
 * The home search and /trips after the UX review (docs/UX-REVIEW.md, fixes 1, 2, 5 and 8).
 * "Now" is Tuesday 2026-11-10 10:00. Default trip on 2026-11-12: Agadir 08:00 → Essaouira 09:45
 * → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats.
 */
final class SearchPagesTest extends WebFlowTestCase
{
    // ---- fix 1: the home search works on its own example ---------------------------------------

    public function testTheHomeSearchSuggestsTheRouteOfItsOwnExample(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorExists('.hero input[name="from"][placeholder="Essaouira"]');
        self::assertSelectorExists('.hero input[name="to"][placeholder="Marrakech"]');
        self::assertSelectorExists('.hero input[name="date"][value=""]', 'no date means « the next departures »');
        self::assertSelectorNotExists('.hero input[name="date"][required]');
        self::assertSelectorExists('.hero a[href="/trips?from=Essaouira&to=Marrakech"]');
        self::assertSelectorTextContains('.hero a[href="/trips?from=Essaouira&to=Marrakech"]', 'Essaouira → Marrakech');
    }

    // ---- fix 2: /trips never opens empty -------------------------------------------------------

    public function testWithoutASearchTheNextDeparturesAreListedDayByDay(): void
    {
        $this->db->trip(stops: [['Marrakech', '18:00', 90], ['Casablanca', '21:00', null]], day: '2026-11-10');
        $this->db->trip(stops: [['Agadir', '07:00', 45], ['Tiznit', '08:30', null]], day: '2026-11-11');
        $this->db->trip();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/trips');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Prochains départs');
        self::assertSame(
            ["Aujourd'hui", 'Demain', 'jeudi 12 novembre'],
            $crawler->filter('.upcoming-day__title')->each(static fn ($n) => $n->text()),
        );
        $thursday = $crawler->filter('.upcoming-day')->eq(2)->text();
        self::assertStringContainsString('Agadir · Essaouira · Marrakech · Casablanca', $thursday, 'every stop, so a portion can be spotted');
        self::assertStringContainsString('dès 60 MAD', $thursday);
        self::assertSelectorExists(sprintf('.upcoming-day a[href^="/trips/"]'));
    }

    public function testWithNoTripAtAllTheListSaysSoAndOffersToPublish(): void
    {
        $this->client->request('GET', '/trips');

        self::assertSelectorTextContains('h1', 'Prochains départs');
        self::assertStringContainsString('Aucun départ annoncé pour le moment', $this->pageText());
        self::assertSelectorExists('a[href="/trips/new"]');
    }

    public function testASearchWithoutADateListsTheNextDeparturesOnThatRoute(): void
    {
        $this->db->trip(day: '2026-11-11');
        $this->db->trip(day: '2026-11-13');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/trips?from=Essaouira&to=Marrakech');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.result'));
        self::assertStringContainsString('Demain', $this->pageText());
        self::assertStringContainsString('vendredi 13 novembre', $this->pageText());
        self::assertSelectorNotExists('form.searchbar input[name="date"][required]');
    }

    // ---- fix 5: a full portion is shown as full, not as « nobody » -----------------------------

    public function testAPortionFullOnThatDayIsShownAsFull(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 3); // Agadir → Essaouira is full
        $this->em->flush();

        $crawler = $this->client->request('GET', '/trips?from=Agadir&to=Marrakech&date=2026-11-12');

        self::assertCount(1, $crawler->filter('.result'));
        self::assertSelectorTextContains('.result', 'Complet sur votre portion');
        self::assertStringNotContainsString('Aucun conducteur', $this->pageText());
    }

    public function testTheHomeCardSaysCompletOnlyWhenEveryLegIsFull(): void
    {
        $partly = $this->db->trip();
        $this->db->booking($partly, 0, 1, 3); // only Agadir → Essaouira is full
        $full = $this->db->trip(driver: $this->db->user('Hamid'));
        $this->db->booking($full, 0, 3, 3);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        $partlyCard = $crawler->filter(sprintf('.route-card[href="/trips/%d"]', $partly->getId()))->text();
        self::assertStringContainsString('Places sur une partie du trajet', $partlyCard);
        self::assertStringNotContainsString('Complet', $partlyCard);
        self::assertStringContainsString('dès 65 MAD', $partlyCard, 'the 60 MAD leg is full: « dès » counts legs with a seat');
        self::assertStringContainsString('Complet', $crawler->filter(sprintf('.route-card[href="/trips/%d"]', $full->getId()))->text());
    }

    // ---- fix 8: after a search, the form folds on the phone -----------------------------------

    public function testAfterASearchTheFormFoldsIntoASummary(): void
    {
        $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', '/trips?from=Essaouira&to=Marrakech&date=2026-11-12&seats=2');

        self::assertSelectorExists('details.search-summary form.searchbar');
        self::assertSelectorTextContains('details.search-summary summary', 'Essaouira → Marrakech');
        self::assertSelectorTextContains('details.search-summary summary', 'jeu. 12 nov.');
        self::assertSelectorTextContains('details.search-summary summary', '2 passagers');
        self::assertSelectorTextContains('details.search-summary summary', 'Modifier');
    }

    public function testBeforeASearchTheFormIsNotFolded(): void
    {
        $this->client->request('GET', '/trips');

        self::assertSelectorExists('form.searchbar');
        self::assertSelectorNotExists('details.search-summary');
    }
}
