<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;

/**
 * Seat choice pages (boards « Choisir ses places » and « Place déjà réservée », v2).
 * Trip: Agadir(0) → Essaouira(1) 09:45 → Marrakech(2) 12:00 → Casablanca(3), Dacia Logan,
 * 3 seats offered: A1, B1, B3 open, B2 « Non proposée ». Essaouira → Marrakech costs 65 MAD.
 * Every seat on the plan carries data-seat="<code>" and data-state="free|freed|taken|not_offered".
 */
final class SeatChoiceTest extends WebFlowTestCase
{
    private function salma(): User
    {
        $salma = $this->db->user('Salma');
        $this->login($salma);

        return $salma;
    }

    private function seatPage(Trip $trip, int $from = 1, int $to = 2, int $seats = 1): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', sprintf('/trips/%d/seats?from=%d&to=%d&seats=%d', $trip->getId(), $from, $to, $seats));
    }

    /** Ticks the box of one seat on the seat page's form. */
    private static function tick(\Symfony\Component\DomCrawler\Form $form, string $code): void
    {
        foreach ($form['seatCodes'] as $box) {
            if (\in_array($code, $box->availableOptionValues(), true)) {
                $box->tick();

                return;
            }
        }
        self::fail("No box for seat $code");
    }

    private function bookingOf(User $user): ?Booking
    {
        $this->em->clear();

        return $this->em->getRepository(Booking::class)->findOneBy(['passenger' => $user->getId()]);
    }

    // ---- from the trip page to the seat page -------------------------------------------------

    public function testReserverOnTheTripPageOpensTheSeatPage(): void
    {
        $trip = $this->db->trip();
        $this->salma();

        $crawler = $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));
        $form = $crawler->selectButton('Réserver')->form(['seats' => 2]);
        self::assertSame('GET', $form->getMethod());
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Choisissez vos 2 places');
    }

    public function testTheSeatPageNeedsALogin(): void
    {
        $trip = $this->db->trip();
        $this->em->flush();

        $this->seatPage($trip);

        self::assertResponseRedirects('/login');
    }

    // ---- the plan ----------------------------------------------------------------------------

    public function testThePlanShowsEverySeatWithItsState(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, seatCodes: ['A1']);   // whole trip
        $this->db->booking($trip, 0, 1, seatCodes: ['B3']);   // gets off at Essaouira
        $this->salma();

        $this->seatPage($trip);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Choisissez votre place');
        self::assertStringContainsString('Berline', $this->pageText());
        foreach (['A1' => 'taken', 'B1' => 'free', 'B2' => 'not_offered', 'B3' => 'freed'] as $code => $state) {
            self::assertSelectorExists(sprintf('[data-seat="%s"][data-state="%s"]', $code, $state), $code);
        }
        foreach (['occupée', 'libre', 'non proposée', 'libérée en route'] as $word) {
            self::assertStringContainsString($word, $this->pageText());
        }
    }

    public function testOnlyOpenSeatsCanBeTicked(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, seatCodes: ['A1']);
        $this->salma();

        $this->seatPage($trip);

        self::assertSelectorExists('input[type="checkbox"][name="seatCodes[]"][value="B1"]:not([disabled])');
        self::assertSelectorExists('input[type="checkbox"][name="seatCodes[]"][value="A1"][disabled]');
        self::assertSelectorExists('input[type="checkbox"][name="seatCodes[]"][value="B2"][disabled]');
    }

    public function testTakenSeatsNeverSayWhoSitsThere(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, passenger: $this->db->user('Rachid'), seatCodes: ['A1']);
        $this->salma();

        $this->seatPage($trip);

        self::assertStringNotContainsString('Rachid', $this->pageText());
    }

    public function testAMonospaceShowsItsThirdRow(): void
    {
        $trip = $this->db->trip(seatsOffered: 6, passengerSeats: 6);
        $this->salma();

        $this->seatPage($trip);

        self::assertStringContainsString('Monospace', $this->pageText());
        self::assertSelectorExists('[data-seat="C2"][data-state="free"]');
    }

    public function testAskingForMorePlacesThanLeftGoesBackToTheTripWithTheReason(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 1, 2, 2, seatCodes: ['A1', 'B1']);
        $this->salma();

        $this->seatPage($trip, seats: 2);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString("Il ne reste qu'une place sur cette portion du trajet.", $this->pageText());
    }

    // ---- booking ------------------------------------------------------------------------------

    public function testBookingTheTickedSeats(): void
    {
        $trip = $this->db->trip();
        $salma = $this->salma();

        $crawler = $this->seatPage($trip, seats: 2);
        $form = $crawler->selectButton('Réserver')->form();
        self::tick($form, 'A1');
        self::tick($form, 'B3');
        $this->client->submit($form);

        $booking = $this->bookingOf($salma);
        self::assertNotNull($booking);
        self::assertSame(['A1', 'B3'], $booking->getSeatCodes());
        self::assertSame(130, $booking->getTotalPrice());
        self::assertResponseRedirects(sprintf('/bookings/%d', $booking->getId()));

        $this->client->followRedirect();
        self::assertStringContainsString('Places A1 et B3', $this->pageText());
    }

    public function testNoPreferenceLetsRihlaChoose(): void
    {
        $trip = $this->db->trip();
        $salma = $this->salma();

        $crawler = $this->seatPage($trip);
        $this->client->submit($crawler->selectButton('Pas de préférence, choisir pour moi')->form());

        self::assertSame(['A1'], $this->bookingOf($salma)->getSeatCodes());
        $this->client->followRedirect();
        self::assertStringContainsString('Place A1', $this->pageText());
    }

    public function testASeatTakenMeanwhileShowsTheSeatTakenPageWithAnotherSeat(): void
    {
        $trip = $this->db->trip();
        $salma = $this->salma();
        $crawler = $this->seatPage($trip);
        $form = $crawler->selectButton('Réserver')->form();
        self::tick($form, 'B3');

        $this->db->booking($trip, 0, 3, passenger: $this->db->user('Rachid'), seatCodes: ['B3']); // someone else was faster
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('h1', "La place B3 vient d'être réservée");
        self::assertStringContainsString('Réserver la place A1', $this->pageText());
        self::assertSelectorExists(sprintf('a[href*="/trips/%d/seats"]', $trip->getId()), '« Revoir le plan des places »');
        self::assertNull($this->bookingOf($salma));

        $this->client->submit($this->client->getCrawler()->selectButton('Réserver la place A1')->form());
        self::assertSame(['A1'], $this->bookingOf($salma)->getSeatCodes());
    }

    // ---- seats shown after booking ------------------------------------------------------------

    public function testTheDriverSeesEachPassengersSeat(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $this->db->booking($trip, 1, 2, passenger: $this->db->user('Salma'), seatCodes: ['B3']);
        $this->db->booking($trip, 0, 3, passenger: $this->db->user('Mehdi')); // made before seat choice
        $this->login($karim);

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Place B3', $this->pageText());
        self::assertStringContainsString('Place A1', $this->pageText(), 'an older booking shows the seat Rihla gave it');
    }
}
