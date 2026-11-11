<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;

/**
 * What a passenger does in the browser: search, open a trip, book a portion, cancel, review.
 * Route names and form fields are the contract the controllers must follow.
 */
final class PassengerFlowTest extends WebFlowTestCase
{
    public function testTheHomePageOffersTheSearch(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/trips"] input[name="from"]');
        self::assertSelectorExists('form[action="/trips"] input[name="to"]');
        self::assertSelectorExists('form[action="/trips"] input[name="date"]');
    }

    public function testSearchingAPortionShowsTheTripWithThePriceOfThatPortion(): void
    {
        $trip = $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', '/trips?from=Essaouira&to=Marrakech&date=2026-11-12&seats=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('65 MAD', $this->pageText());
        self::assertSelectorExists(sprintf('a[href^="/trips/%d"]', $trip->getId()));
    }

    public function testCityNamesAreMatchedWhateverTheCase(): void
    {
        $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', '/trips?from=essaouira&to=MARRAKECH&date=2026-11-12');

        self::assertStringContainsString('65 MAD', $this->pageText());
    }

    public function testASearchWithoutResultSaysSo(): void
    {
        $this->db->trip();
        $this->em->flush();

        $this->client->request('GET', '/trips?from=Marrakech&to=Agadir&date=2026-11-12');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun trajet', $this->pageText());
    }

    public function testTheTripPageShowsTheStopsTheDriverAndTheBookingForm(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $this->em->flush();

        $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));

        self::assertResponseIsSuccessful();
        $text = $this->pageText();
        foreach (['Agadir', 'Essaouira', 'Marrakech', 'Casablanca', 'Karim T.'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertSelectorExists(sprintf('form[action="/trips/%d/seats"][method="get"] select[name="seats"]', $trip->getId()), 'Réserver opens the seat page');
    }

    public function testAnAnonymousVisitorIsSentToTheLoginPageToBook(): void
    {
        $trip = $this->db->trip();
        $this->em->flush();

        $this->client->request('POST', sprintf('/trips/%d/book', $trip->getId()), ['from' => 1, 'to' => 2, 'seats' => 1]);

        self::assertResponseRedirects('/login');
    }

    public function testBookingAPortionFromTheTripPage(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $this->login($salma);

        $crawler = $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));
        $crawler = $this->client->submit($crawler->selectButton('Réserver')->form(['seats' => 2]));
        $this->client->submit($crawler->selectButton('Pas de préférence, choisir pour moi')->form());

        $booking = $this->em->getRepository(Booking::class)->findOneBy(['passenger' => $salma]);
        self::assertNotNull($booking, 'the booking is saved');
        self::assertSame(1, $booking->getFromStop()->getPosition());
        self::assertSame(2, $booking->getToStop()->getPosition());
        self::assertSame(130, $booking->getTotalPrice());
        self::assertResponseRedirects(sprintf('/bookings/%d', $booking->getId()));

        $this->client->followRedirect();
        self::assertStringContainsString('Réservation confirmée', $this->pageText());
        self::assertStringContainsString('130 MAD', $this->pageText());
    }

    public function testBookingMoreSeatsThanLeftShowsTheReasonAndSavesNothing(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 1, 2, 2);
        $salma = $this->db->user('Salma');
        $this->login($salma);

        $crawler = $this->client->request('GET', sprintf('/trips/%d?from=0&to=3', $trip->getId()));
        $form = $crawler->selectButton('Réserver')->form();
        $form['seats']->disableValidation()->setValue('3');
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertStringContainsString("Il ne reste qu'une place sur cette portion du trajet.", $this->pageText());
        self::assertNull($this->em->getRepository(Booking::class)->findOneBy(['passenger' => $salma]));
    }

    public function testMyTripsListsTheBookingsOfThePassenger(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $salma);
        $this->login($salma);

        $this->client->request('GET', '/bookings');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Essaouira', $this->pageText());
        self::assertStringContainsString('Marrakech', $this->pageText());
    }

    public function testCancellingShowsTheRefundFirstThenCancels(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $salma); // 100 MAD, two days before
        $this->login($salma);

        $crawler = $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('100 MAD', $this->pageText(), 'full refund more than 24 h before');

        $this->client->submit($crawler->selectButton("Confirmer l'annulation")->form());

        self::assertResponseRedirects(sprintf('/bookings/%d', $booking->getId()));
        $booking = $this->reload($booking);
        self::assertSame(BookingStatus::CancelledByPassenger, $booking->getStatus());
    }

    public function testAnotherUserCannotSeeOrCancelSomeoneElsesBooking(): void
    {
        $booking = $this->db->booking($this->db->trip(), 1, 2);
        $this->login($this->db->user('Omar'));

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePassengerReviewsTheDriverAfterACompletedTrip(): void
    {
        $karim = $this->db->user('Karim');
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(driver: $karim), 1, 2, 1, BookingStatus::Completed, $salma);
        static::mockTime('2026-11-13 10:00');
        $this->login($salma);

        $crawler = $this->client->request('GET', sprintf('/bookings/%d/review', $booking->getId()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Publier mon avis')->form([
            'review[rating]' => 5,
            'review[comment]' => 'Ponctuel et prudent.',
        ]));

        self::assertResponseRedirects();
        $this->client->request('GET', sprintf('/users/%d', $karim->getId()));
        self::assertStringContainsString('Ponctuel et prudent.', $this->pageText());
        self::assertStringContainsString('5,0', $this->pageText());
    }

    public function testNoReviewFormBeforeTheTripIsCompleted(): void
    {
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(), 1, 2, 1, BookingStatus::Confirmed, $salma);
        $this->login($salma);

        $this->client->request('GET', sprintf('/bookings/%d/review', $booking->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testPrivatePagesNeedToBeLoggedIn(): void
    {
        foreach (['/bookings', '/driver/trips', '/trips/new', '/account', '/account/vehicles'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', null, $url);
        }
    }

    public function testRegisteringCreatesTheAccountAndLogsIn(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration[firstName]' => 'Yasmine',
            'registration[lastName]' => 'Alaoui',
            'registration[email]' => 'yasmine.test@rihla.ma',
            'registration[phone]' => '0612345678',
            'registration[plainPassword]' => 'un-mot-de-passe-solide',
        ]));

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'yasmine.test@rihla.ma']);
        self::assertNotNull($user);
        self::assertNotSame('un-mot-de-passe-solide', $user->getPassword(), 'the password is hashed');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('a[href="/logout"]');
    }

    public function testAnEmailCanOnlyBeRegisteredOnce(): void
    {
        $existing = $this->db->user('Salma');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration[firstName]' => 'Salma',
            'registration[lastName]' => 'Bis',
            'registration[email]' => $existing->getEmail(),
            'registration[plainPassword]' => 'un-mot-de-passe-solide',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Un compte existe déjà avec cette adresse e-mail.', $this->pageText());
    }

    public function testThePublicProfileShowsTheDriverWithoutPrivateDetails(): void
    {
        $karim = $this->db->user('Karim');
        $karim->setPhone('0600000000');
        $this->em->flush();

        $this->client->request('GET', sprintf('/users/%d', $karim->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Karim T.', $this->pageText());
        self::assertStringNotContainsString($karim->getEmail(), $this->pageText());
        self::assertStringNotContainsString('0600000000', $this->pageText());
    }
}
