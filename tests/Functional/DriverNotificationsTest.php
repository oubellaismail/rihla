<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * Drivers are told, under the same bell and by e-mail, when someone books or cancels on their trip and when their
 * trip is cancelled from the back-office; a new review about them only rings the bell.
 * "Now" is 2026-11-10 10:00. Default trip on Thursday 2026-11-12: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 →
 * Casablanca 15:00, legs 60 / 65 / 85 MAD (DbTrips bookings cost 100 MAD per seat).
 */
final class DriverNotificationsTest extends WebFlowTestCase
{
    /** @return list<Email> e-mails sent during the last request */
    private function sent(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email));
    }

    private function notices(User $user): string
    {
        $this->login($this->reload($user));
        $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();

        return $this->pageText();
    }

    // ---- a new booking ----------------------------------------------------------------------------

    public function testTheDriverIsToldWhoBooked(): void
    {
        $trip = $this->db->trip();
        $driver = $trip->getDriver();
        $this->db->vehicle($driver);
        $salma = $this->db->user('Salma');
        $this->login($salma);

        $crawler = $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));
        $crawler = $this->client->submit($crawler->selectButton('Choisir ma place')->form(['seats' => 1]));
        $this->client->submit($crawler->selectButton('Pas de préférence, choisir pour moi')->form());

        $emails = array_values(array_filter($this->sent(), static fn (Email $e): bool => $e->getTo()[0]->getAddress() === $driver->getEmail()));
        self::assertCount(1, $emails, 'one e-mail to the driver');
        self::assertSame('Nouvelle réservation : Salma T., Essaouira → Marrakech', $emails[0]->getSubject());
        $text = (string) $emails[0]->getTextBody();
        self::assertStringContainsString('1 place', $text);
        self::assertStringContainsString('65 MAD', $text);
        self::assertStringContainsString('jeudi 12 novembre', $text);
        self::assertStringContainsString(sprintf('/driver/trips/%d', $trip->getId()), $text, 'a link to the driver trip page');

        $page = $this->notices($driver);
        self::assertStringContainsString('Nouvelle réservation : Salma T., Essaouira → Marrakech', $page);
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter(sprintf('main a[href="/driver/trips/%d"]', $trip->getId()))->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'Voir le trajet')));
    }

    // ---- a passenger cancels ----------------------------------------------------------------------

    public function testTheDriverIsToldWhoCancelled(): void
    {
        $trip = $this->db->trip();
        $driver = $trip->getDriver();
        $youssef = $this->db->user('Youssef');
        $booking = $this->db->booking($trip, 0, 1, passenger: $youssef);
        $this->login($youssef);

        $crawler = $this->client->request('GET', sprintf('/bookings/%d/cancel', $booking->getId()));
        $this->client->submit($crawler->selectButton("Confirmer l'annulation")->form());

        $emails = array_values(array_filter($this->sent(), static fn (Email $e): bool => $e->getTo()[0]->getAddress() === $driver->getEmail()));
        self::assertCount(1, $emails);
        self::assertSame('Réservation annulée : Youssef T., Agadir → Essaouira', $emails[0]->getSubject());
        self::assertStringContainsString('1 place libérée', (string) $emails[0]->getTextBody());

        self::assertStringContainsString('Réservation annulée : Youssef T., Agadir → Essaouira', $this->notices($driver));
    }

    // ---- the trip is cancelled from the back-office -----------------------------------------------

    public function testTheDriverIsToldWhenRihlaCancelsTheTrip(): void
    {
        $trip = $this->db->trip();
        $driver = $trip->getDriver();
        $this->db->booking($trip, 0, 1, passenger: $this->db->user('Mehdi'));
        $this->db->booking($trip, 1, 2, passenger: $this->db->user('Salma'));
        $this->login($this->db->user('Admin')->setRoles(['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', sprintf('/admin/trip/%d', $trip->getId()));
        $this->client->submit($crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), 'Annuler le trajet'))->form());

        $emails = array_values(array_filter($this->sent(), static fn (Email $e): bool => $e->getTo()[0]->getAddress() === $driver->getEmail()));
        self::assertCount(1, $emails, 'the driver, besides the two passengers');
        self::assertSame('Votre trajet Agadir → Casablanca a été annulé par Rihla', $emails[0]->getSubject());
        self::assertStringContainsString('2 passagers remboursés intégralement', (string) $emails[0]->getTextBody());

        self::assertStringContainsString('Votre trajet Agadir → Casablanca a été annulé par Rihla', $this->notices($driver));
    }

    public function testADriverCancellingTheirOwnTripIsNotToldAboutIt(): void
    {
        $trip = $this->db->trip();
        $driver = $trip->getDriver();
        $this->db->booking($trip, 0, 1, passenger: $this->db->user('Mehdi'));
        $this->login($driver);

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));
        $this->client->submit($crawler->selectButton('Annuler le trajet')->form());

        self::assertCount(0, array_filter($this->sent(), static fn (Email $e): bool => $e->getTo()[0]->getAddress() === $driver->getEmail()));
        self::assertStringContainsString('Aucune notification pour le moment.', $this->notices($driver));
    }

    // ---- a new review -----------------------------------------------------------------------------

    public function testANewReviewRingsTheBellWithoutAnEmail(): void
    {
        $karim = $this->db->user('Karim');
        $salma = $this->db->user('Salma');
        $booking = $this->db->booking($this->db->trip(driver: $karim), 1, 2, 1, BookingStatus::Completed, $salma);
        static::mockTime('2026-11-13 10:00');
        $this->login($salma);

        $crawler = $this->client->request('GET', sprintf('/bookings/%d/review', $booking->getId()));
        $this->client->submit($crawler->selectButton('Publier mon avis')->form([
            'review[rating]' => 4,
            'review[comment]' => 'Ponctuel et prudent.',
        ]));

        self::assertCount(0, $this->sent(), 'no e-mail for a review');
        $page = $this->notices($karim);
        self::assertStringContainsString('Nouvel avis de Salma T. : 4/5', $page);
        self::assertStringContainsString('Ponctuel et prudent.', $page);
        self::assertCount(1, $this->client->getCrawler()->filter(sprintf('main a[href="/users/%d"]', $karim->getId()))->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'Voir mon profil')));
    }

    public function testThePassengerNoticesStillWork(): void
    {
        $trip = $this->db->trip();
        $salma = $this->db->user('Salma');
        $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->login($trip->getDriver());
        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d/delay?minutes=30', $trip->getId()));
        $this->client->submit($crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), 'Confirmer'))->form());

        self::assertStringContainsString('Votre trajet Essaouira → Marrakech part 30 min plus tard', $this->notices($salma));
        self::assertStringContainsString('Aucune notification pour le moment.', $this->notices($this->em->find(Trip::class, $trip->getId())->getDriver()), 'the driver made the change');
    }
}
