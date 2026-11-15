<?php

namespace App\Tests\Functional;

use App\Entity\User;

/**
 * An admin account only manages the back-office: every page outside /admin sends it to /admin
 * (it cannot search, book or publish a trip). Members are not affected.
 */
final class AdminConfinementTest extends WebFlowTestCase
{
    private function admin(): User
    {
        $admin = $this->db->user('Admin')->setRoles(['ROLE_ADMIN']);
        $this->login($admin);

        return $admin;
    }

    /** @return iterable<string, array{string}> */
    public static function sitePages(): iterable
    {
        yield 'home' => ['/'];
        yield 'search' => ['/trips?from=Agadir&to=Marrakech'];
        yield 'upcoming' => ['/trips'];
        yield 'publish' => ['/trips/new'];
        yield 'bookings' => ['/bookings'];
        yield 'driver space' => ['/driver'];
        yield 'account' => ['/account'];
        yield 'vehicles' => ['/account/vehicles'];
        yield 'login page' => ['/login'];
        yield 'register page' => ['/register'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sitePages')]
    public function testAnAdminIsSentBackToTheBackOffice(string $url): void
    {
        $this->admin();

        $this->client->request('GET', $url);

        self::assertResponseRedirects('/admin');
    }

    public function testATripPageAndAProfileAreOutsideToo(): void
    {
        $trip = $this->db->trip();
        $this->admin();

        $this->client->request('GET', sprintf('/trips/%d', $trip->getId()));
        self::assertResponseRedirects('/admin');

        $this->client->request('GET', sprintf('/users/%d', $trip->getDriver()->getId()));
        self::assertResponseRedirects('/admin');
    }

    public function testAnAdminCannotBookOrPublishByPosting(): void
    {
        $trip = $this->db->trip();
        $this->admin();

        $this->client->request('POST', sprintf('/trips/%d/book', $trip->getId()));
        self::assertResponseRedirects('/admin');

        $this->client->request('POST', '/trips/new');
        self::assertResponseRedirects('/admin');
    }

    public function testTheBackOfficeStillOpens(): void
    {
        $this->admin();

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/trip');
        self::assertResponseIsSuccessful();
    }

    public function testAnAdminCanStillLogOut(): void
    {
        $this->admin();

        $this->client->request('GET', '/logout');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAMemberStillUsesTheSite(): void
    {
        $this->login($this->db->user('Karim'));

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/trips/new');
        self::assertResponseRedirects('/account/vehicles', null, 'no car yet: the normal flow, not the back-office');
    }

    public function testAVisitorStillUsesTheSite(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }
}
