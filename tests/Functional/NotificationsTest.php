<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The notification bell: what the drivers changed on a passenger's trips, inside Rihla. Each notice is created with
 * the e-mail of the same event (delay, stop removed, trip cancelled) and says the same thing.
 * "Now" is 2026-11-10 10:00. Default trip on Thursday 2026-11-12: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 →
 * Casablanca 15:00 (DbTrips bookings cost 100 MAD per seat).
 */
final class NotificationsTest extends WebFlowTestCase
{
    private function confirm(string $url, string $button): void
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);
        $this->client->submit($crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), $button))->form());
    }

    private function delay(Trip $trip, int $minutes): void
    {
        $this->login($trip->getDriver());
        $this->confirm(sprintf('/driver/trips/%d/delay?minutes=%d', $trip->getId(), $minutes), 'Confirmer');
    }

    private function bell(): Crawler
    {
        return $this->client->getCrawler()->filter('header a.notification-bell[href="/notifications"]');
    }

    /** @return array{0: Trip, 1: User, 2: Booking} */
    private function salmaBooked(): array
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);

        return [$trip, $salma, $booking];
    }

    // ---- the bell ---------------------------------------------------------------------------------

    public function testAMemberHasABellAndAVisitorDoesNot(): void
    {
        $this->client->request('GET', '/');
        self::assertCount(0, $this->bell());

        $this->login($this->db->user('Salma'));
        $this->client->request('GET', '/');
        self::assertCount(1, $this->bell());
        self::assertStringContainsString('Notifications', (string) $this->bell()->attr('aria-label'));
        self::assertCount(0, $this->bell()->filter('.notification-bell__count'), 'nothing unread');
    }

    public function testTheBellCountsWhatIsUnread(): void
    {
        [$trip, $salma] = $this->salmaBooked();
        $this->delay($trip, 15);
        $this->delay($trip, 30);
        $this->login($this->reload($salma));

        $this->client->request('GET', '/');

        self::assertSame('2', trim($this->bell()->filter('.notification-bell__count')->text()));
        self::assertStringContainsString('2', (string) $this->bell()->attr('aria-label'), 'the count is read out too');
    }

    // ---- the page ---------------------------------------------------------------------------------

    public function testADelayAppearsWithALinkToTheBooking(): void
    {
        [$trip, $salma, $booking] = $this->salmaBooked();
        $this->delay($trip, 45);
        $this->login($this->reload($salma));

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Notifications');
        $text = $this->pageText();
        self::assertStringContainsString('Votre trajet Essaouira → Marrakech part 45 min plus tard', $text);
        self::assertStringContainsString('10:30 au lieu de 09:45', $text);
        self::assertStringContainsString('annuler sans frais', $text);
        self::assertCount(1, $crawler->filter(sprintf('main a[href="/bookings/%d"]', $booking->getId()))->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'Voir ma réservation')));
    }

    public function testARemovedStopReachesOnlyWhoseBookingIsCancelled(): void
    {
        $trip = $this->db->trip();
        $youssef = $this->db->user('Youssef');
        $mehdi = $this->db->user('Mehdi');
        $this->db->booking($trip, 0, 1, passenger: $youssef);
        $this->db->booking($trip, 0, 2, passenger: $mehdi);
        $this->login($trip->getDriver());
        $this->confirm(sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $trip->getStops()->get(1)->getId()), 'Retirer');

        $this->login($this->reload($youssef));
        $this->client->request('GET', '/notifications');
        self::assertStringContainsString("Réservation annulée : l'étape Essaouira est retirée", $this->pageText());
        self::assertStringContainsString('Remboursement intégral : 100 MAD', $this->pageText());

        $this->login($this->reload($mehdi));
        $this->client->request('GET', '/notifications');
        self::assertStringContainsString('Aucune notification pour le moment.', $this->pageText());
    }

    public function testACancelledTripAppears(): void
    {
        [$trip, $salma] = $this->salmaBooked();
        $this->login($trip->getDriver());
        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));
        $this->client->submit($crawler->selectButton('Annuler le trajet')->form());
        $this->login($this->reload($salma));

        $this->client->request('GET', '/notifications');

        self::assertStringContainsString('Trajet annulé : Agadir → Casablanca, jeudi 12 novembre', $this->pageText());
    }

    public function testNewestFirst(): void
    {
        [$trip, $salma] = $this->salmaBooked();
        $this->delay($trip, 15);
        static::mockTime('2026-11-10 11:00');
        $this->delay($trip, 30);
        $this->login($this->reload($salma));

        $crawler = $this->client->request('GET', '/notifications');

        $titles = $crawler->filter('.notification')->each(static fn (Crawler $n): string => $n->text());
        self::assertCount(2, $titles);
        self::assertStringContainsString('30 min plus tard', $titles[0]);
        self::assertStringContainsString('15 min plus tard', $titles[1]);
    }

    public function testOpeningThePageMarksEverythingAsRead(): void
    {
        [$trip, $salma] = $this->salmaBooked();
        $this->delay($trip, 15);
        $this->login($this->reload($salma));

        $crawler = $this->client->request('GET', '/notifications');
        self::assertCount(1, $crawler->filter('.notification--unread'), 'shown as new this time');

        $crawler = $this->client->request('GET', '/notifications');
        self::assertCount(0, $crawler->filter('.notification--unread'));
        self::assertCount(0, $this->bell()->filter('.notification-bell__count'));
    }

    public function testEachPassengerSeesOnlyTheirOwn(): void
    {
        [$trip] = $this->salmaBooked();
        $this->delay($trip, 30);
        $this->login($this->db->user('Omar'));

        $this->client->request('GET', '/notifications');

        self::assertStringContainsString('Aucune notification pour le moment.', $this->pageText());
    }

    public function testAVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/notifications');

        self::assertResponseRedirects('/login');
    }
}
