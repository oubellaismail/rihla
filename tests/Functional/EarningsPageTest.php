<?php

namespace App\Tests\Functional;

use App\Enum\BookingStatus;
use App\Enum\TripStatus;

/**
 * The « Mes gains » page of the driver. The numbers come from DriverEarnings (tests/Integration/Service/DriverEarningsTest.php);
 * this only checks that the page shows them. "Now" is 2026-11-10 10:00.
 */
final class EarningsPageTest extends WebFlowTestCase
{
    public function testAVisitorIsSentToLogIn(): void
    {
        $this->client->request('GET', '/driver/earnings');

        self::assertResponseRedirects('/login');
    }

    public function testTheDriverSeesWhatHeEarnedAndWillEarn(): void
    {
        $karim = $this->db->user('Karim');
        $done = $this->db->trip(driver: $karim, day: '2026-10-05')->setStatus(TripStatus::Completed);
        $this->db->booking($done, 0, 1, status: BookingStatus::Completed, passenger: $this->db->user('Mehdi'));
        $this->db->booking($done, 1, 3, status: BookingStatus::Completed, passenger: $this->db->user('Youssef'));
        $next = $this->db->trip(driver: $karim, day: '2026-11-12');
        $this->db->booking($next, 0, 2, 2, passenger: $this->db->user('Rachid'));
        $this->login($karim);

        $crawler = $this->client->request('GET', '/driver/earnings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mes gains');
        self::assertSelectorTextContains('.earnings-kpi--earned', '200 MAD');
        self::assertSelectorTextContains('.earnings-kpi--upcoming', '200 MAD');
        self::assertSelectorTextContains('.earnings-kpi--seats', '4');
        self::assertCount(6, $crawler->filter('.earnings-chart .earnings-chart__month'));
        self::assertSelectorTextContains('.earnings-resold', '1 place revendue');
        self::assertSelectorTextContains('.earnings-fill', '39 %');
        self::assertCount(2, $crawler->filter('.earnings-table tbody tr'));
        self::assertSelectorExists(sprintf('.earnings-table a[href="/driver/trips/%d"]', $next->getId()));
    }

    public function testAnEmptyPageInvitesToPublish(): void
    {
        $this->login($this->db->user('Leila'));

        $this->client->request('GET', '/driver/earnings');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Vous n’avez encore rien gagné', $this->pageText());
        self::assertSelectorExists('a[href="/trips/new"]');
    }

    public function testTheDriverMenuLeadsToMesGains(): void
    {
        $this->login($this->db->user('Karim'));

        $this->client->request('GET', '/driver/earnings');

        self::assertSelectorExists('nav.space-nav a[href="/driver/earnings"][aria-current="page"]');
    }
}
