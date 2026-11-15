<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lists must not run queries per row (N+1): the number of SQL queries of a page stays the same
 * whether it shows one trip or six, each with its own driver and a paid booking.
 * "Now" is 2026-11-10 10:00; the default trip leaves on 2026-11-12 (Agadir → Essaouira → Marrakech → Casablanca).
 */
final class QueryCountTest extends WebFlowTestCase
{
    private const NAMES = ['Hamid', 'Nadia', 'Youssef', 'Rachid', 'Samira', 'Omar'];

    /** Adds $count trips on 2026-11-12, each by its own driver (or by $driver) with one paid booking. */
    private function trips(int $count, ?User $driver = null): void
    {
        $passenger = $this->db->user('Laila');
        for ($i = 0; $i < $count; ++$i) {
            $trip = $this->db->trip(driver: $driver ?? $this->db->user(self::NAMES[$i]));
            $this->db->booking($trip, 1, 2, passenger: $passenger);
        }
        $this->em->flush();
    }

    /** SQL queries run while serving $url. */
    private function queries(string $url): int
    {
        $this->client->request('GET', $url); // warm-up: one-off work (session, finishing past trips) is not per trip
        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        WebTestCase::assertResponseIsSuccessful();

        return $this->client->getProfile()->getCollector('db')->getQueryCount();
    }

    private function assertFlat(string $url, ?User $driver = null): void
    {
        $this->trips(1, $driver);
        $one = $this->queries($url);

        $this->trips(5, null === $driver ? null : $this->reload($driver));
        $six = $this->queries($url);

        self::assertSame($one, $six, sprintf('%s ran %d queries for 1 trip and %d for 6: some run once per trip.', $url, $one, $six));
    }

    public function testSearchResultsDoNotQueryPerTrip(): void
    {
        $this->assertFlat('/trips?from=Essaouira&to=Marrakech&date=2026-11-12');
    }

    public function testSearchWithoutADateDoesNotQueryPerTrip(): void
    {
        $this->assertFlat('/trips?from=Essaouira&to=Marrakech');
    }

    public function testUpcomingListDoesNotQueryPerTrip(): void
    {
        $this->assertFlat('/trips');
    }

    public function testDriverTripsDoNotQueryPerTrip(): void
    {
        $karim = $this->db->user('Karim');
        $this->login($karim);

        $this->assertFlat('/driver/trips', $karim);
    }

    public function testRatingsLoadedTogetherStayWithTheirDriver(): void
    {
        $good = $this->db->user('Hamid');
        $fair = $this->db->user('Nadia');
        $this->db->trip(driver: $good, stops: [['Essaouira', '09:00', 70], ['Marrakech', '11:00', null]]);
        $this->db->trip(driver: $fair, stops: [['Essaouira', '10:00', 70], ['Marrakech', '12:00', null]]);
        $this->db->trip(driver: $this->db->user('Omar'), stops: [['Essaouira', '11:00', 70], ['Marrakech', '13:00', null]]);
        $this->rate($good, 5);
        $this->rate($good, 4);
        $this->rate($fair, 3);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/trips?from=Essaouira&to=Marrakech&date=2026-11-12');

        $ratings = $crawler->filter('.result .result-driver .rating')->each(static fn ($n): string => preg_replace('/\s+/', ' ', trim($n->text())));
        self::assertSame(['4,5 (2 avis)', '3,0 (1 avis)', 'Nouveau sur Rihla'], $ratings);
    }

    private function rate(User $driver, int $rating): void
    {
        $trip = $this->db->trip(driver: $driver, day: '2026-11-01');
        $booking = $this->db->booking($trip, 0, 1, passenger: $this->db->user('Rachid'));
        $this->em->persist((new \App\Entity\Review())->setBooking($booking)->setAuthor($booking->getPassenger())->setTarget($driver)->setRating($rating));
    }
}
