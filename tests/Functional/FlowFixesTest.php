<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;

/**
 * Fixes from the UX review of the flows (docs/UX-REVIEW-FLOWS.md, fixes 1 to 9).
 * "Now" is 2026-11-10 10:00 unless a test says otherwise. Default trip on 2026-11-12:
 * Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00, legs 60 / 65 / 85 MAD, 3 seats.
 */
final class FlowFixesTest extends WebFlowTestCase
{
    private function passenger(string $name = 'Salma'): User
    {
        $user = $this->db->user($name);
        $this->login($user);

        return $user;
    }

    private function bookingOf(User $user): ?Booking
    {
        $this->em->clear();

        return $this->em->getRepository(Booking::class)->findOneBy(['passenger' => $user->getId()]);
    }

    // ---- fix 1: the portion you see is the portion you book ----------------------------------

    public function testChangingThePortionAppliesItAtOnce(): void
    {
        $trip = $this->db->trip();
        $this->passenger();

        $crawler = $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));

        self::assertSelectorExists('form.portion-form[data-controller~="portion"]');
        self::assertCount(2, $crawler->filter('form.portion-form select[data-action*="change->portion#apply"]'), 'both selects reload the portion');
    }

    public function testAnImpossiblePortionSaysWhy(): void
    {
        $trip = $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', sprintf('/trips/%d?from=2&to=1', $trip->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("L'arrêt de descente doit venir après celui de montée.", $this->pageText());
    }

    // ---- fix 2: trips finish on their own; cancelling twice is explained ----------------------

    public function testAFinishedTripIsCompletedWhenThePassengerOpensMyTrips(): void
    {
        $trip = $this->db->trip();
        $salma = $this->passenger();
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->em->flush();
        static::mockTime('2026-11-13 10:00');

        $this->client->request('GET', '/bookings');
        self::assertStringContainsString('Terminée', $this->pageText());

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));
        self::assertSelectorExists(sprintf('a[href="/bookings/%d/review"]', $booking->getId()), '« Laisser un avis » appears for a real booking');
        self::assertSame(BookingStatus::Completed, $this->bookingOf($salma)->getStatus());
    }

    public function testAFinishedTripIsCompletedWhenTheDriverOpensMyTrips(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $this->login($karim);
        static::mockTime('2026-11-13 10:00');

        $this->client->request('GET', '/driver/trips');

        $this->em->clear();
        self::assertSame(TripStatus::Completed, $this->em->find(Trip::class, $trip->getId())->getStatus());
    }

    public function testOpeningTheCancelPageOfACancelledBookingExplainsInsteadOfForbidding(): void
    {
        $trip = $this->db->trip();
        $salma = $this->passenger();
        $booking = $this->db->booking($trip, 1, 2, status: BookingStatus::CancelledByPassenger, passenger: $salma);
        $this->em->flush();

        $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));

        self::assertResponseRedirects(sprintf('/bookings/%d', $booking->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Cette réservation est déjà annulée.', $this->pageText());
    }

    public function testSomeoneElsesBookingIsStillForbidden(): void
    {
        $trip = $this->db->trip();
        $booking = $this->db->booking($trip, 1, 2, passenger: $this->db->user('Rachid'));
        $this->passenger();

        $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    // ---- fix 3: bags survive a booking conflict ----------------------------------------------

    public function testTheSeatTakenPageKeepsTheBags(): void
    {
        $trip = $this->db->trip();
        $salma = $this->passenger();
        $crawler = $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=1', $trip->getId()));
        $form = $crawler->selectButton('Réserver')->form(['bags[large]' => 1]);
        foreach ($form['seatCodes'] as $box) {
            if (\in_array('B3', $box->availableOptionValues(), true)) {
                $box->tick();
            }
        }
        $this->db->booking($trip, 0, 3, passenger: $this->db->user('Rachid'), seatCodes: ['B3']);

        $crawler = $this->client->submit($form);

        self::assertSelectorExists('.seat-taken__offer input[type="hidden"][name="bags[large]"][value="1"]');
        $this->client->submit($crawler->filter('.seat-taken__offer')->form());
        self::assertSame(['cabin' => 0, 'medium' => 0, 'large' => 1], $this->bookingOf($salma)->getBags(), 'booked with the suitcase');
    }

    public function testATrunkFilledMeanwhileIsExplainedInBags(): void
    {
        $trip = $this->db->trip();
        $this->passenger();
        $crawler = $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=1', $trip->getId()));
        $form = $crawler->selectButton('Pas de préférence, choisir pour moi')->form(['bags[large]' => 1]);
        $this->db->booking($trip, 0, 3, 2, passenger: $this->db->user('Rachid'), seatCodes: ['A1', 'B3'], bags: ['large' => 3, 'medium' => 1]); // 54 L left

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Vos bagages ne passent plus sur cette portion : il reste de la place pour un bagage cabine.', $this->pageText());
        self::assertStringNotContainsString('54 L', $this->pageText());
    }

    // ---- fix 4: the meeting point is never a hidden required field ----------------------------

    /** @param array<string, mixed> $agadir */
    private function publish(array $agadir): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim);
        $this->login($karim);
        $crawler = $this->client->request('GET', '/trips/new');
        $form = $crawler->selectButton('Publier le trajet')->form();
        $values = $form->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        $values['trip']['stops'] = [
            ['city' => $this->db->city('Agadir')->getId(), 'departureAt' => '2026-11-12T08:00', 'legPriceToNext' => 60] + $agadir,
            ['city' => $this->db->city('Essaouira')->getId(), 'departureAt' => '2026-11-12T09:45', 'meetingPoint' => 'Bab Marrakech', 'legPriceToNext' => ''],
        ];
        $this->client->request('POST', $form->getUri(), $values);
    }

    public function testAPointPlacedOnTheMapIsEnoughWithoutWords(): void
    {
        $this->publish(['meetingPoint' => '', 'meetingLatitude' => '30.4202', 'meetingLongitude' => '-9.5982']);

        self::assertResponseRedirects();
        $this->em->clear();
        $trip = $this->em->getRepository(Trip::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame('Point sur la carte, Agadir', $trip->getFirstStop()->getMeetingPoint());
    }

    public function testNoPointAndNoWordsAsksForOneOfThem(): void
    {
        $this->publish(['meetingPoint' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisissez un point de rendez-vous sur la carte ou décrivez-le.', $this->pageText());
    }

    // ---- fix 5: the driver sees who gets off -------------------------------------------------

    public function testTheDriverSeesWhoGetsOffAtEachStop(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $this->db->booking($trip, 0, 1, passenger: $this->db->user('Mehdi'));
        $this->db->booking($trip, 0, 2, passenger: $this->db->user('Youssef'));
        $this->login($karim);

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        $essaouira = $crawler->filter('.timeline > li')->eq(1)->text();
        self::assertStringContainsString('Descend ici : Mehdi T.', $essaouira);
        $marrakech = $crawler->filter('.timeline > li')->eq(2)->text();
        self::assertStringContainsString('Descend ici : Youssef T.', $marrakech);
        self::assertStringNotContainsString('Descend ici', $crawler->filter('.timeline > li')->eq(0)->text());
    }

    // ---- fix 6: errors are announced, logging out says so ------------------------------------

    public function testErrorMessagesAreAnnouncedAsAlerts(): void
    {
        $trip = $this->db->trip();
        $salma = $this->passenger();
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->em->flush();

        $this->client->request('POST', sprintf('/bookings/%d/cancel', $booking->getId()), ['_token' => 'expired']);
        $this->client->followRedirect();

        self::assertSelectorExists('.flash--error[role="alert"]');
    }

    public function testLoggingOutSaysSo(): void
    {
        $this->passenger();
        $this->client->request('GET', '/');

        $this->client->request('GET', '/logout');
        $this->client->followRedirect();

        self::assertStringContainsString('Vous êtes déconnecté.', $this->pageText());
    }

    // ---- fix 7: trust on the seat page --------------------------------------------------------

    public function testTheSeatPageSaysThePaymentIsSimulatedAndTheWholeRefundPolicy(): void
    {
        $trip = $this->db->trip();
        $this->passenger();

        $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=1', $trip->getId()));

        $note = $this->client->getCrawler()->filter('.seats-note')->text();
        self::assertStringContainsString('Paiement simulé', $note);
        self::assertStringContainsString('remboursement intégral jusqu\'à 24 h avant le départ, 50 % ensuite, rien après le départ', $note);
    }

    // ---- fix 8: bookable from a later stop after the first one left ---------------------------

    public function testATripStaysBookableFromAStopNotYetReached(): void
    {
        $trip = $this->db->trip();
        $this->passenger();
        $this->em->flush();
        static::mockTime('2026-11-12 09:00'); // gone from Agadir, not yet from Essaouira

        $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));
        self::assertSelectorExists('.price-box button[type="submit"]', 'not « Déjà parti »');
        self::assertStringNotContainsString('Déjà parti', $this->pageText());

        $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=1', $trip->getId()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/trips/%d?from=0&to=1', $trip->getId()));
        self::assertStringContainsString('Déjà parti', $this->pageText(), 'from Agadir it did leave');
    }

    // ---- fix 9: the publish form says when the route is being calculated ---------------------

    public function testThePublishFormHasALiveRouteStatus(): void
    {
        $karim = $this->db->user('Karim');
        $this->db->vehicle($karim);
        $this->login($karim);

        $this->client->request('GET', '/trips/new');

        self::assertSelectorExists('[data-publish-map-target="status"][role="status"]');
    }
}
