<?php

namespace App\Tests\Functional;

use App\Entity\Review;
use App\Enum\BookingStatus;

/**
 * Passenger side of the desktop review (docs/UX-REVIEW-DESKTOP.md, fixes 2, 3, 8, 9).
 * "Now" is 2026-11-10 10:00. Default trip on 2026-11-12: Agadir 08:00 → Essaouira 09:45
 * → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats.
 */
final class DesktopPassengerTest extends WebFlowTestCase
{
    // ---- fix 2: the flagship lands on a portion that can be booked -----------------------------

    public function testATripOpenedWithoutPortionShowsTheFirstPortionWithASeat(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 3); // Agadir → Essaouira is full
        $this->em->flush();

        $this->client->request('GET', sprintf('/trips/%d', $trip->getId()));

        self::assertSelectorTextContains('h1', 'Essaouira');
        self::assertSelectorTextContains('h1', 'Casablanca');
        self::assertSelectorTextContains('.price-box', '150 MAD', '65 + 85');
        self::assertSelectorExists('.price-box button[type="submit"]', 'bookable, not « Complet sur cette portion »');
    }

    public function testATripWithSeatsAllTheWayStillOpensOnTheWholeTrip(): void
    {
        $trip = $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', sprintf('/trips/%d', $trip->getId()));

        self::assertSelectorTextContains('h1', 'Agadir');
        self::assertSelectorTextContains('.price-box', '210 MAD');
    }

    public function testDesFromOnlyCountsLegsWithASeat(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 3); // the 60 MAD leg is full
        $this->em->flush();

        $card = $this->client->request('GET', '/')->filter(sprintf('.route-card[href="/trips/%d"]', $trip->getId()))->text();
        self::assertStringContainsString('dès 65 MAD', $card);

        $this->client->request('GET', '/trips');
        self::assertStringContainsString('dès 65 MAD', $this->client->getCrawler()->filter('.upcoming')->text());
    }

    // ---- fix 3: seats and price on each leg ------------------------------------------------------

    public function testEachLegOfTheTimelineCarriesItsSeatsAndPrice(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, 3);
        $this->db->booking($trip, 1, 2, 2);
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));

        self::assertSame(
            ['Complet · 60 MAD', '1 place libre · 65 MAD', '3 places libres · 85 MAD'],
            $crawler->filter('.timeline .leg-pill')->each(static fn ($n) => trim(preg_replace('/\s+/', ' ', $n->text()))),
        );
        self::assertStringContainsString('is-full', (string) $crawler->filter('.leg-pill')->eq(0)->attr('class'));
        self::assertStringContainsString('is-mine', (string) $crawler->filter('.leg-pill')->eq(1)->attr('class'), 'the passenger\'s own leg');
        self::assertSelectorNotExists('.timeline .leg-seats', 'the seat count no longer sits on the stop');
    }

    // ---- fix 8: « choisir pour moi » picks, it does not book ------------------------------------

    public function testChoosingForMePicksTheSeatsOnThePage(): void
    {
        $trip = $this->db->trip();
        $this->login($this->db->user('Salma'));

        $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=1', $trip->getId()));

        self::assertSelectorExists('button[name="auto"][data-action*="seat-picker#autoPick"]', 'with JavaScript it ticks the best seats; without it, it still submits');
    }

    // ---- fix 9: one drawn route, honest testimonials, four photos -------------------------------

    public function testTheBookingPageTitleUsesTheDrawnRoute(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->login($salma);

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertSelectorExists('h1 .route svg');
        self::assertStringNotContainsString('→', $this->client->getCrawler()->filter('h1')->text());
    }

    public function testHomeTestimonialsArePassengersSpeakingOfDrivers(): void
    {
        $trip = $this->db->trip();
        $karim = $trip->getDriver();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, status: BookingStatus::Completed, passenger: $salma);
        $this->em->persist((new Review())->setBooking($booking)->setAuthor($salma)->setTarget($karim)->setRating(5)->setComment('Conduite très agréable, je recommande.'));
        $this->em->persist((new Review())->setBooking($booking)->setAuthor($karim)->setTarget($salma)->setRating(5)->setComment('Passagère à l\'heure, merci !'));
        $this->em->flush();

        $this->client->request('GET', '/');

        self::assertStringContainsString('Conduite très agréable, je recommande.', $this->pageText());
        self::assertStringNotContainsString('Passagère à l\'heure', $this->pageText(), 'a driver rating a passenger is not a testimonial');
    }

    public function testTheRouteCardsDoNotRepeatAPhoto(): void
    {
        $this->db->trip(stops: [['Agadir', '08:00', 60], ['Casablanca', '15:00', null]]);
        $this->db->trip(stops: [['Agadir', '09:00', 35], ['Tiznit', '10:30', null]]);
        $this->db->trip(stops: [['Essaouira', '07:30', 70], ['Marrakech', '10:10', null]]);
        $this->db->trip(stops: [['Marrakech', '09:00', 110], ['Ouarzazate', '13:00', null]]);
        $this->em->flush();

        $photos = $this->client->request('GET', '/')->filter('.route-card img')->each(static fn ($img) => $img->attr('src'));

        self::assertCount(4, $photos);
        self::assertSame($photos, array_values(array_unique($photos)));
    }
}
