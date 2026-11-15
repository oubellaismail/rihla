<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\Booking;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Service\Geo\RouteEnricher;
use App\Tests\Support\FakeGeoApis;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The demo data a reviewer gets on a fresh install: a platform that looks alive, with upcoming trips over the next
 * three weeks, two months of past trips with reviews both ways, and cancellations. Dates are relative to the moment
 * of loading; "now" is mocked to the real time here so the pages agree with the data.
 *
 * Road data comes from OSRM (faked in tests): asked once per pair of points, and never blocking the load.
 */
final class DemoDataTest extends WebFlowTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable();
        static::mockTime($this->now);
    }

    private function load(): void
    {
        (new ORMPurger($this->em))->purge();
        $container = static::getContainer();
        $fixtures = new AppFixtures($container->get(UserPasswordHasherInterface::class), $container->get(RouteEnricher::class));
        $fixtures->load($this->em);
        $this->em->flush();
        $this->em->clear();
    }

    /** @return list<Trip> */
    private function trips(?TripStatus $status = null): array
    {
        return $this->em->getRepository(Trip::class)->findBy(null === $status ? [] : ['status' => $status]);
    }

    /** @return list<Trip> published trips whose first stop is still ahead */
    private function upcoming(): array
    {
        return array_values(array_filter($this->trips(TripStatus::Published), fn (Trip $t): bool => $t->getFirstStop()->getDepartureAt() > $this->now));
    }

    private function user(string $email): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    // ---- volume -----------------------------------------------------------------------------------

    public function testThePlatformLooksAlive(): void
    {
        $this->load();

        self::assertGreaterThanOrEqual(20, \count($this->em->getRepository(User::class)->findAll()), 'users');
        self::assertGreaterThanOrEqual(20, \count($this->upcoming()), 'upcoming trips');
        self::assertGreaterThanOrEqual(25, \count($this->trips(TripStatus::Completed)), 'completed trips');
        self::assertGreaterThanOrEqual(2, \count($this->trips(TripStatus::Cancelled)), 'trips cancelled by their driver');
        self::assertGreaterThanOrEqual(40, \count($this->em->getRepository(Review::class)->findAll()), 'reviews');
        self::assertGreaterThanOrEqual(2, \count($this->em->getRepository(Booking::class)->findBy(['status' => BookingStatus::CancelledByPassenger])), 'bookings cancelled by passengers');

        $drivers = array_unique(array_map(static fn (Trip $t): int => $t->getDriver()->getId(), $this->trips()));
        self::assertGreaterThanOrEqual(8, \count($drivers), 'drivers');
    }

    public function testUpcomingTripsStartSoonAndSpreadOverThreeWeeks(): void
    {
        $this->load();

        $departures = array_map(static fn (Trip $t): \DateTimeImmutable => $t->getFirstStop()->getDepartureAt(), $this->upcoming());
        $soon = array_filter($departures, fn (\DateTimeImmutable $d): bool => $d <= $this->now->modify('+24 hours'));
        $late = array_filter($departures, fn (\DateTimeImmutable $d): bool => $d >= $this->now->modify('+14 days'));
        self::assertNotEmpty($soon, 'something leaves within 24 hours');
        self::assertGreaterThanOrEqual(3, \count($late), 'and the calendar goes on beyond two weeks');

        $withStops = array_filter($this->upcoming(), static fn (Trip $t): bool => $t->getStops()->count() >= 3);
        self::assertGreaterThanOrEqual(6, \count($withStops), 'trips with stops on the way, the heart of the subject');
    }

    public function testPastTripsCoverTheLastTwoMonths(): void
    {
        $this->load();

        $departures = array_map(static fn (Trip $t): \DateTimeImmutable => $t->getFirstStop()->getDepartureAt(), $this->trips(TripStatus::Completed));
        foreach ($departures as $d) {
            self::assertLessThan($this->now, $d, 'a completed trip is in the past');
        }
        self::assertNotEmpty(array_filter($departures, fn (\DateTimeImmutable $d): bool => $d <= $this->now->modify('-45 days')), 'history goes back about two months');
    }

    public function testTheFlagshipTripIsKept(): void
    {
        $this->load();

        $karim = $this->user('karim@rihla.ma');
        $routes = array_map(
            static fn (Trip $t): string => implode(' → ', $t->getStops()->map(static fn ($s) => $s->getCity()->getName())->toArray()),
            array_filter($this->upcoming(), static fn (Trip $t): bool => $t->getDriver()->getId() === $karim->getId()),
        );
        self::assertContains('Agadir → Essaouira → Marrakech → Casablanca', $routes);
    }

    // ---- reviews ----------------------------------------------------------------------------------

    public function testReviewsAreHonestAndBelongToRealTrips(): void
    {
        $this->load();

        $reviews = $this->em->getRepository(Review::class)->findAll();
        $ratings = array_unique(array_map(static fn (Review $r): int => $r->getRating(), $reviews));
        self::assertGreaterThanOrEqual(3, \count($ratings), 'not every review is 5 stars');

        $byDriver = 0;
        foreach ($reviews as $review) {
            $booking = $review->getBooking();
            self::assertSame(BookingStatus::Completed, $booking->getStatus(), 'reviews follow a completed trip');
            $passenger = $booking->getPassenger();
            $driver = $booking->getTrip()->getDriver();
            $pair = [$review->getAuthor()->getId(), $review->getTarget()->getId()];
            self::assertContains($pair, [[$passenger->getId(), $driver->getId()], [$driver->getId(), $passenger->getId()]]);
            if ($review->getAuthor()->getId() === $driver->getId()) {
                ++$byDriver;
            }
            self::assertNotSame('', trim((string) $review->getComment()));
        }
        self::assertGreaterThanOrEqual(10, $byDriver, 'drivers review their passengers too');
    }

    // ---- the demo accounts ------------------------------------------------------------------------

    public function testKarimHasMonthsOfEarnings(): void
    {
        $this->load();

        $karim = $this->user('karim@rihla.ma');
        $months = [];
        foreach ($this->trips(TripStatus::Completed) as $trip) {
            if ($trip->getDriver()->getId() === $karim->getId()) {
                $months[$trip->getFirstStop()->getDepartureAt()->format('Y-m')] = true;
            }
        }
        self::assertGreaterThanOrEqual(3, \count($months), '« Mes gains » shows several months');
        self::assertGreaterThanOrEqual(6, \count($this->em->getRepository(Review::class)->findBy(['target' => $karim])), 'reviews on his profile');
    }

    public function testSalmaHasEveryKindOfBooking(): void
    {
        $this->load();

        $statuses = array_map(
            static fn (Booking $b): BookingStatus => $b->getStatus(),
            $this->em->getRepository(Booking::class)->findBy(['passenger' => $this->user('salma@rihla.ma')]),
        );
        foreach ([BookingStatus::Confirmed, BookingStatus::Completed] as $expected) {
            self::assertContains($expected, $statuses);
        }
        self::assertNotEmpty(array_filter($statuses, static fn (BookingStatus $s): bool => $s->isCancelled()), 'a cancelled one too');
    }

    // ---- what the reviewer sees -------------------------------------------------------------------

    public function testUpcomingDeparturesShowFullAndPartlyFullTrips(): void
    {
        $this->load();

        $this->client->request('GET', '/trips');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Complet', $this->pageText());
        self::assertStringContainsString('Places sur une partie du trajet', $this->pageText());
    }

    public function testTheReadmeTourWorks(): void
    {
        $this->load();

        $crawler = $this->client->request('GET', '/trips?from=Agadir&to=Tiznit');

        self::assertGreaterThanOrEqual(1, $crawler->filter('.result')->count(), 'search Agadir → Tiznit finds a trip');
    }

    public function testEveryDemoAccountSignsInWithThePassword(): void
    {
        $this->load();

        foreach (['karim@rihla.ma' => '/', 'salma@rihla.ma' => '/', 'imane@rihla.ma' => '/', 'admin@rihla.ma' => '/admin'] as $email => $home) {
            $this->client->restart();
            $crawler = $this->client->request('GET', '/login');
            $this->client->submit($crawler->filter('form')->first()->form(['_username' => $email, '_password' => AppFixtures::DEMO_PASSWORD]));
            self::assertResponseRedirects();
            $this->client->followRedirects();
            $this->client->request('GET', '/');
            $this->client->followRedirects(false);
            self::assertStringEndsWith($home, (string) $this->client->getRequest()->getPathInfo(), $email);
        }
    }

    public function testTheLoginPageHasAnAdminDemoButton(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $button = $crawler->filter('.demo-accounts button[data-email="admin@rihla.ma"]');
        self::assertCount(1, $button);
        self::assertSame('Admin, back-office', trim($button->text()));
    }

    // ---- road data --------------------------------------------------------------------------------

    public function testOsrmIsAskedOncePerPairOfPoints(): void
    {
        $this->load();

        $pairs = [];
        foreach ($this->trips() as $trip) {
            $previous = null;
            foreach ($trip->getStops() as $stop) {
                if (null !== $previous) {
                    $pairs[$previous->getCity()->getName().'|'.$previous->getMeetingPoint().'>'.$stop->getCity()->getName().'|'.$stop->getMeetingPoint()] = true;
                }
                $previous = $stop;
            }
        }
        $routeCalls = array_filter(FakeGeoApis::$calls, static fn (string $url): bool => str_contains($url, '/route/v1/driving/'));
        self::assertLessThanOrEqual(\count($pairs), \count($routeCalls));
        self::assertLessThan(\count($this->trips()), \count($routeCalls), 'fewer calls than trips: shared legs are asked once');
    }

    public function testTheDataLoadsWhenOsrmIsDown(): void
    {
        FakeGeoApis::$down = true;

        $this->load();

        self::assertGreaterThanOrEqual(20, \count($this->upcoming()));
        $routeCalls = array_filter(FakeGeoApis::$calls, static fn (string $url): bool => str_contains($url, '/route/v1/driving/'));
        self::assertLessThanOrEqual(3, \count($routeCalls), 'after a failure, the load stops waiting for OSRM');
    }
}
