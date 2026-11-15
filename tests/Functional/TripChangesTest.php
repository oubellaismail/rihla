<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Tests\Support\FakeGeoApis;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A driver changes a trip after bookings: « Décaler l'horaire » and « Retirer une étape ».
 * "Now" is 2026-11-10 10:00. Default trip on 2026-11-12: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 →
 * Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats (DbTrips bookings cost 100 MAD per seat).
 *
 * Delay: every stop moves by 15, 30, 45, 60, 120 or 180 minutes, never earlier; delays add up. Passengers see a
 * banner; from 30 minutes in total they can cancel free of charge (full refund even within the last 24 hours).
 * Stop removal: only an intermediate stop. Bookings boarding or leaving there are cancelled by the driver and fully
 * refunded, with the reason; bookings passing through keep their seat and price; the two legs become one.
 * Both changes show their effect on a preview page first, then need a confirmation (POST with a CSRF token).
 */
final class TripChangesTest extends WebFlowTestCase
{
    private function driverOf(Trip $trip): User
    {
        $driver = $trip->getDriver();
        $this->login($driver);

        return $driver;
    }

    /** @return list<string> departure times of the trip's stops, as Y-m-d H:i, in order */
    private function times(Trip $trip): array
    {
        $this->em->clear();
        $fresh = $this->em->find(Trip::class, $trip->getId());

        return $fresh->getStops()->map(static fn (TripStop $s): string => $s->getDepartureAt()->format('Y-m-d H:i'))->getValues();
    }

    private function delay(Trip $trip, int $minutes): void
    {
        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d/delay?minutes=%d', $trip->getId(), $minutes));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), 'Confirmer'))->form());
    }

    private function removeStop(Trip $trip, TripStop $stop): void
    {
        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $stop->getId()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), 'Retirer'))->form());
    }

    // ---- delay: the driver ------------------------------------------------------------------------

    public function testTheDriverPageOffersTheDelays(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        $form = $crawler->filter(sprintf('form[method="get"][action="/driver/trips/%d/delay"]', $trip->getId()));
        self::assertCount(1, $form);
        self::assertSame(['15', '30', '45', '60', '120', '180'], $form->filter('[name="minutes"]')->each(static fn (Crawler $i): string => (string) $i->attr('value')));
        self::assertStringContainsString("Décaler l'horaire", $crawler->text());
    }

    public function testThePreviewShowsTheEffectAndChangesNothing(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, passenger: $this->db->user('Youssef'));
        $this->db->booking($trip, 1, 2, passenger: $this->db->user('Salma'));
        $this->driverOf($trip);

        $this->client->request('GET', sprintf('/driver/trips/%d/delay?minutes=45', $trip->getId()));

        self::assertResponseIsSuccessful();
        $text = $this->pageText();
        self::assertStringContainsString("Départ d'Agadir à 08:45 au lieu de 08:00", $text);
        self::assertStringContainsString('2 passagers concernés', $text);
        self::assertStringContainsString('ils pourront annuler sans frais', $text);
        self::assertSame('2026-11-12 08:00', $this->times($trip)[0], 'nothing saved before the confirmation');
    }

    public function testAShortDelayIsNotAFreeCancellation(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1);
        $this->driverOf($trip);

        $this->client->request('GET', sprintf('/driver/trips/%d/delay?minutes=15', $trip->getId()));

        self::assertStringNotContainsString('sans frais', $this->pageText());
    }

    public function testConfirmingShiftsEveryStop(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3);
        $this->driverOf($trip);

        $this->delay($trip, 45);

        self::assertResponseRedirects(sprintf('/driver/trips/%d', $trip->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Trajet décalé de 45 min.', $this->pageText());
        self::assertSame(['2026-11-12 08:45', '2026-11-12 10:30', '2026-11-12 12:45', '2026-11-12 15:45'], $this->times($trip));
        self::assertSame(45, $this->em->find(Trip::class, $trip->getId())->getDelayMinutes());
    }

    public function testDelaysAddUp(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);

        $this->delay($trip, 15);
        $this->delay($trip, 30);

        self::assertSame('2026-11-12 08:45', $this->times($trip)[0]);
        self::assertSame(45, $this->em->find(Trip::class, $trip->getId())->getDelayMinutes());
    }

    public function testOnlyTheOfferedDelaysAreAccepted(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);
        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d/delay?minutes=30', $trip->getId()));
        $form = $crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), 'Confirmer'))->form();

        foreach (['-15', '20', '240'] as $minutes) {
            $values = $form->getPhpValues();
            $values['minutes'] = $minutes;
            $this->client->request('POST', $form->getUri(), $values);
            self::assertSame('2026-11-12 08:00', $this->times($trip)[0], $minutes.' is refused');
        }
    }

    public function testTheConfirmationNeedsAValidToken(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);

        $this->client->request('POST', sprintf('/driver/trips/%d/delay', $trip->getId()), ['minutes' => '30', '_token' => 'expired']);

        self::assertSame('2026-11-12 08:00', $this->times($trip)[0]);
    }

    public function testAStartedOrCancelledTripCannotBeDelayed(): void
    {
        $started = $this->db->trip(day: '2026-11-10', stops: [['Agadir', '08:00', 60], ['Marrakech', '12:00', null]]);
        $cancelled = $this->db->trip();
        $cancelled->setStatus(TripStatus::Cancelled);
        $this->em->flush();

        foreach ([$started, $cancelled] as $trip) {
            $this->login($trip->getDriver());
            $this->client->request('POST', sprintf('/driver/trips/%d/delay', $trip->getId()), ['minutes' => '30']);
            $first = $this->times($trip)[0];
            self::assertStringEndsWith('08:00', $first);
            $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));
            self::assertStringNotContainsString("Décaler l'horaire", $this->pageText());
        }
    }

    public function testNobodyElseCanDelayTheTrip(): void
    {
        $trip = $this->db->trip();
        $this->login($this->db->user('Omar'));

        $this->client->request('GET', sprintf('/driver/trips/%d/delay?minutes=30', $trip->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/driver/trips/%d/delay', $trip->getId()), ['minutes' => '30']);
        self::assertResponseStatusCodeSame(403);
    }

    // ---- delay: the passenger ---------------------------------------------------------------------

    public function testThePassengerSeesTheNewTimeAndCanCancelFreeOfCharge(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->driverOf($trip);
        $this->delay($trip, 45);
        $this->login($this->reload($salma));

        $crawler = $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        $text = $this->pageText();
        self::assertStringContainsString('Horaire modifié par le conducteur', $text);
        self::assertStringContainsString("Départ d'Essaouira à 10:30 au lieu de 09:45 (45 min de retard)", $text);
        self::assertCount(1, $crawler->filter(sprintf('a[href="/bookings/%d/cancel"]', $booking->getId()))->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'Annuler sans frais')));
    }

    public function testUnderThirtyMinutesThereIsNoFreeCancellation(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->driverOf($trip);
        $this->delay($trip, 15);
        $this->login($this->reload($salma));

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertStringContainsString('Horaire modifié par le conducteur', $this->pageText());
        self::assertStringNotContainsString('Annuler sans frais', $this->pageText());
    }

    public function testThirtyMinutesLateMeansAFullRefundEvenTheDayBefore(): void
    {
        $trip = $this->db->trip(day: '2026-11-10', stops: [['Agadir', '20:00', 60], ['Marrakech', '23:30', null]]);
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 0, 1, passenger: $salma); // 100 MAD, ten hours before: normally half
        $this->driverOf($trip);
        $this->delay($trip, 30);
        $this->login($this->reload($salma));

        $crawler = $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));
        self::assertStringContainsString('100 MAD', $this->pageText());
        $this->client->submit($crawler->selectButton("Confirmer l'annulation")->form());

        $saved = $this->reload($booking);
        self::assertSame(BookingStatus::CancelledByPassenger, $saved->getStatus());
        self::assertSame(100, $saved->getPayment()->getRefundedAmount());
    }

    public function testWithoutADelayTheDayBeforeIsHalf(): void
    {
        $trip = $this->db->trip(day: '2026-11-10', stops: [['Agadir', '20:00', 60], ['Marrakech', '23:30', null]]);
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 0, 1, passenger: $salma);
        $this->login($salma);

        $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));

        self::assertStringContainsString('50 MAD', $this->pageText());
    }

    public function testMyBookingsFlagsTheChangedTime(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->driverOf($trip);
        $this->delay($trip, 15);
        $this->login($this->reload($salma));

        $this->client->request('GET', '/bookings');

        self::assertStringContainsString('Horaire modifié', $this->pageText());
    }

    // ---- a booking made after a delay ------------------------------------------------------------

    /** Books one seat from Agadir to Marrakech through the pages (BookingService), as a new passenger. */
    private function bookThroughThePages(Trip $trip, string $name): Booking
    {
        $passenger = $this->db->user($name);
        $this->login($passenger);
        $crawler = $this->client->request('GET', sprintf('/trips/%d?from=0&to=1', $trip->getId()));
        $crawler = $this->client->submit($crawler->selectButton('Choisir ma place')->form(['seats' => 1]));
        $this->client->submit($crawler->selectButton('Pas de préférence, choisir pour moi')->form());

        return $this->em->getRepository(Booking::class)->findOneBy(['passenger' => $this->reload($passenger)]);
    }

    public function testABookingMadeAfterADelayIsNotFlagged(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);
        $this->delay($trip, 45);

        $booking = $this->bookThroughThePages($this->em->find(Trip::class, $trip->getId()), 'Lina');
        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertStringNotContainsString('Horaire modifié', $this->pageText(), 'she booked the new time');
        self::assertStringNotContainsString('Annuler sans frais', $this->pageText());
    }

    public function testABookingMadeAfterADelayKeepsTheUsualRefund(): void
    {
        $trip = $this->db->trip(day: '2026-11-10', stops: [['Agadir', '20:00', 60], ['Marrakech', '23:30', null]]);
        $this->driverOf($trip);
        $this->delay($trip, 30);

        $booking = $this->bookThroughThePages($this->em->find(Trip::class, $trip->getId()), 'Lina'); // 60 MAD, the same day
        $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));

        self::assertStringContainsString('30 MAD', $this->pageText(), 'half, as for any booking within 24 hours');
    }

    public function testOnlyTheDelayAfterTheBookingCounts(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);
        $this->delay($trip, 45);
        $booking = $this->bookThroughThePages($this->em->find(Trip::class, $trip->getId()), 'Lina');
        $this->login($this->em->find(Trip::class, $trip->getId())->getDriver());
        $this->delay($trip, 15);
        $this->login($this->reload($booking)->getPassenger());

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertStringContainsString("Départ d'Agadir à 09:00 au lieu de 08:45 (15 min de retard)", $this->pageText());
        self::assertStringNotContainsString('Annuler sans frais', $this->pageText(), '15 minutes for her, not 1 h');
    }

    // ---- remove a stop: the driver ----------------------------------------------------------------

    public function testOnlyIntermediateStopsCanBeRemoved(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);
        $stops = $trip->getStops()->getValues();

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        $links = $crawler->filter('a[href*="/stops/"][href$="/remove"]')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertSame([
            sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $stops[1]->getId()),
            sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $stops[2]->getId()),
        ], $links);
        self::assertStringContainsString('Retirer une étape', $crawler->text());

        $this->client->request('GET', sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $stops[0]->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $stops[3]->getId()));
        self::assertCount(4, $this->em->find(Trip::class, $trip->getId())->getStops());
    }

    public function testTheRemovalPreviewNamesWhoIsAffected(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, passenger: $this->db->user('Youssef'));
        $this->db->booking($trip, 1, 2, passenger: $this->db->user('Salma'));
        $this->db->booking($trip, 0, 2, passenger: $this->db->user('Mehdi'));
        $this->driverOf($trip);
        $essaouira = $trip->getStops()->get(1);

        $this->client->request('GET', sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $essaouira->getId()));

        $text = $this->pageText();
        self::assertStringContainsString('Retirer Essaouira', $text);
        self::assertStringContainsString('2 réservations seront annulées et remboursées intégralement', $text);
        self::assertStringContainsString('Youssef', $text);
        self::assertStringContainsString('Salma', $text);
        self::assertStringContainsString('Mehdi', $text);
        self::assertStringContainsString('passe par Essaouira', $text);
        self::assertCount(4, $this->em->find(Trip::class, $trip->getId())->getStops(), 'nothing removed before the confirmation');
    }

    public function testRemovingAStopCancelsWhoGetsOnOrOffThereAndKeepsTheOthers(): void
    {
        $trip = $this->db->trip();
        $youssef = $this->db->booking($trip, 0, 1, passenger: $this->db->user('Youssef'));
        $salma = $this->db->booking($trip, 1, 2, passenger: $this->db->user('Salma'));
        $mehdi = $this->db->booking($trip, 0, 2, passenger: $this->db->user('Mehdi'));
        $this->driverOf($trip);
        $essaouira = $trip->getStops()->get(1);

        $this->removeStop($trip, $essaouira);

        self::assertResponseRedirects(sprintf('/driver/trips/%d', $trip->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Étape Essaouira retirée. 2 réservations annulées et remboursées.', $this->pageText());

        $this->em->clear();
        $fresh = $this->em->find(Trip::class, $trip->getId());
        self::assertSame(['Agadir', 'Marrakech', 'Casablanca'], $fresh->getStops()->map(static fn (TripStop $s) => $s->getCity()->getName())->getValues());
        self::assertSame([0, 1, 2], $fresh->getStops()->map(static fn (TripStop $s) => $s->getPosition())->getValues());
        self::assertSame(125, $fresh->getStops()->get(0)->getLegPriceToNext(), 'the two legs become one, at their sum');

        foreach ([$youssef, $salma] as $b) {
            $saved = $this->em->find(Booking::class, $b->getId());
            self::assertSame(BookingStatus::CancelledByDriver, $saved->getStatus());
            self::assertSame($saved->getPayment()->getAmount(), $saved->getPayment()->getRefundedAmount());
            self::assertStringContainsString('Essaouira', (string) $saved->getCancellationReason());
        }
        $kept = $this->em->find(Booking::class, $mehdi->getId());
        self::assertSame(BookingStatus::Confirmed, $kept->getStatus());
        self::assertSame(100, $kept->getTotalPrice(), 'same price');
        self::assertSame('Agadir', $kept->getFromStop()->getCity()->getName());
        self::assertSame('Marrakech', $kept->getToStop()->getCity()->getName());
    }

    public function testTheMergedLegGetsItsRoadFromOsrm(): void
    {
        $trip = $this->db->trip();
        $this->driverOf($trip);
        FakeGeoApis::$calls = [];

        $this->removeStop($trip, $trip->getStops()->get(1));

        self::assertNotEmpty(array_filter(FakeGeoApis::$calls, static fn (string $u): bool => str_contains($u, '/route/v1/driving/')));
        $this->em->clear();
        self::assertNotNull($this->em->find(Trip::class, $trip->getId())->getStops()->get(0)->getLegDistanceKmToNext());
    }

    public function testSeatsAreRightOnTheMergedLeg(): void
    {
        $trip = $this->db->trip(seatsOffered: 3);
        $this->db->booking($trip, 0, 1, 2); // gets off at Essaouira: cancelled by the removal
        $this->db->booking($trip, 0, 2, 1); // through Essaouira: kept
        $this->db->booking($trip, 1, 2, 1); // gets on at Essaouira: cancelled
        $this->driverOf($trip);

        $this->removeStop($trip, $trip->getStops()->get(1));
        $this->client->request('GET', '/trips?from=Agadir&to=Marrakech&date=2026-11-12');

        self::assertStringContainsString('2 places libres', $this->pageText());
    }

    public function testAStartedTripKeepsItsStops(): void
    {
        $trip = $this->db->trip(day: '2026-11-10', stops: [['Agadir', '08:00', 60], ['Essaouira', '11:00', 65], ['Marrakech', '13:00', null]]);
        $this->driverOf($trip);

        $this->client->request('POST', sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $trip->getStops()->get(1)->getId()));

        self::assertCount(3, $this->em->find(Trip::class, $trip->getId())->getStops());
    }

    public function testNobodyElseCanRemoveAStop(): void
    {
        $trip = $this->db->trip();
        $this->login($this->db->user('Omar'));

        $this->client->request('GET', sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $trip->getStops()->get(1)->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    // ---- remove a stop: the passenger -------------------------------------------------------------

    public function testTheCancelledPassengerIsToldWhy(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->driverOf($trip);
        $this->removeStop($trip, $trip->getStops()->get(1));
        $this->login($this->reload($salma));

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertStringContainsString("Le conducteur a retiré l'étape Essaouira.", $this->pageText());
        self::assertStringContainsString('100 MAD', $this->pageText());
    }
}
