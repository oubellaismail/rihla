<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\TripStatus;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * Passengers get an e-mail when the driver changes or cancels their trip, so they know without opening Rihla.
 * In Docker the e-mails land in Mailpit (http://localhost:8025); in tests they are collected by the mailer.
 * "Now" is 2026-11-10 10:00. Default trip on Thursday 2026-11-12: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 →
 * Casablanca 15:00 (DbTrips bookings cost 100 MAD per seat).
 *
 * Also: a trip cannot be delayed by more than 6 hours in total; beyond that, the driver cancels it.
 */
final class EmailNoticesTest extends WebFlowTestCase
{
    /** @return list<Email> e-mails sent during the last request */
    private function sent(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email));
    }

    private function to(Email $email): string
    {
        return $email->getTo()[0]->getAddress();
    }

    private function passenger(string $name): User
    {
        $user = $this->db->user($name);
        $this->em->flush();

        return $user;
    }

    private function confirm(string $url, string $button): void
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);
        $this->client->submit($crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), $button))->form());
    }

    // ---- delay ------------------------------------------------------------------------------------

    public function testADelayIsSentToEveryBookedPassenger(): void
    {
        $trip = $this->db->trip();
        $salma = $this->passenger('Salma');
        $youssef = $this->passenger('Youssef');
        $booking = $this->db->booking($trip, 1, 2, passenger: $salma);
        $this->db->booking($trip, 0, 1, passenger: $youssef);
        $this->login($trip->getDriver());

        $this->confirm(sprintf('/driver/trips/%d/delay?minutes=45', $trip->getId()), 'Confirmer');

        $emails = $this->sent();
        self::assertCount(2, $emails);
        $toSalma = array_values(array_filter($emails, fn (Email $e): bool => $this->to($e) === $salma->getEmail()))[0];
        self::assertSame('Votre trajet Essaouira → Marrakech part 45 min plus tard', $toSalma->getSubject());
        self::assertSame('noreply@rihla.ma', $toSalma->getFrom()[0]->getAddress());
        self::assertSame('Rihla', $toSalma->getFrom()[0]->getName());
        $text = (string) $toSalma->getTextBody();
        self::assertStringContainsString('Bonjour Salma', $text);
        self::assertStringContainsString('10:30 au lieu de 09:45', $text);
        self::assertStringContainsString('annuler sans frais', $text);
        self::assertStringContainsString(sprintf('/bookings/%d', $booking->getId()), $text, 'a link to the booking');
        self::assertStringContainsString('10:30 au lieu de 09:45', (string) $toSalma->getHtmlBody());
    }

    public function testAShortDelayDoesNotOfferAFreeCancellation(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 1, 2, passenger: $this->passenger('Salma'));
        $this->login($trip->getDriver());

        $this->confirm(sprintf('/driver/trips/%d/delay?minutes=15', $trip->getId()), 'Confirmer');

        $emails = $this->sent();
        self::assertCount(1, $emails);
        self::assertSame('Votre trajet Essaouira → Marrakech part 15 min plus tard', $emails[0]->getSubject());
        self::assertStringNotContainsString('sans frais', (string) $emails[0]->getTextBody());
    }

    // ---- stop removed -----------------------------------------------------------------------------

    public function testARemovedStopIsSentOnlyToWhoseBookingIsCancelled(): void
    {
        $trip = $this->db->trip();
        $youssef = $this->passenger('Youssef');
        $this->db->booking($trip, 0, 1, passenger: $youssef);
        $this->db->booking($trip, 0, 2, passenger: $this->passenger('Mehdi')); // passes through: nothing changes for him
        $this->login($trip->getDriver());

        $this->confirm(sprintf('/driver/trips/%d/stops/%d/remove', $trip->getId(), $trip->getStops()->get(1)->getId()), 'Retirer');

        $emails = $this->sent();
        self::assertCount(1, $emails);
        self::assertSame($youssef->getEmail(), $this->to($emails[0]));
        self::assertSame("Réservation annulée : l'étape Essaouira est retirée", $emails[0]->getSubject());
        $text = (string) $emails[0]->getTextBody();
        self::assertStringContainsString('Bonjour Youssef', $text);
        self::assertStringContainsString("a retiré l'étape Essaouira", $text);
        self::assertStringContainsString('100 MAD', $text);
        self::assertStringContainsString('/trips', $text, 'a link to search another trip');
    }

    // ---- trip cancelled ---------------------------------------------------------------------------

    public function testACancelledTripIsSentToEveryBookedPassenger(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, passenger: $this->passenger('Mehdi'));
        $this->db->booking($trip, 1, 3, 2, passenger: $this->passenger('Salma'));
        $this->login($trip->getDriver());

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));
        $this->client->submit($crawler->selectButton('Annuler le trajet')->form());

        $emails = $this->sent();
        self::assertCount(2, $emails);
        foreach ($emails as $email) {
            self::assertSame('Trajet annulé : Agadir → Casablanca, jeudi 12 novembre', $email->getSubject());
            self::assertStringContainsString('Remboursement intégral', (string) $email->getTextBody());
        }
        $toSalma = array_values(array_filter($emails, static fn (Email $e): bool => str_contains((string) $e->getTextBody(), 'Bonjour Salma')));
        self::assertCount(1, $toSalma);
        self::assertStringContainsString('200 MAD', (string) $toSalma[0]->getTextBody());
        self::assertStringContainsString('Essaouira → Casablanca', (string) $toSalma[0]->getTextBody(), 'her portion');
    }

    public function testATripCancelledFromTheBackOfficeIsSentToo(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 1, passenger: $this->passenger('Mehdi'));
        $admin = $this->db->user('Admin')->setRoles(['ROLE_ADMIN']);
        $this->login($admin);

        $this->confirm(sprintf('/admin/trip/%d', $trip->getId()), 'Annuler le trajet');

        self::assertCount(1, $this->sent());
        self::assertSame(TripStatus::Cancelled, $this->em->find(Trip::class, $trip->getId())->getStatus());
    }

    public function testNobodyBookedMeansNoEmail(): void
    {
        $trip = $this->db->trip();
        $this->login($trip->getDriver());

        $this->confirm(sprintf('/driver/trips/%d/delay?minutes=30', $trip->getId()), 'Confirmer');

        self::assertCount(0, $this->sent());
    }

    // ---- the demo mailbox -------------------------------------------------------------------------

    public function testTheLoginPagePointsToTheDemoMailbox(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $link = $crawler->filter('.demo-accounts a[href="http://localhost:8025"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('Voir les e-mails envoyés', $link->text());
    }

    // ---- delay cap --------------------------------------------------------------------------------

    public function testATripCannotBeDelayedBeyondSixHours(): void
    {
        $trip = $this->db->trip();
        $this->login($trip->getDriver());
        $this->confirm(sprintf('/driver/trips/%d/delay?minutes=180', $trip->getId()), 'Confirmer');
        $this->confirm(sprintf('/driver/trips/%d/delay?minutes=120', $trip->getId()), 'Confirmer'); // 5 h in total

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        $offered = $crawler->filter(sprintf('form[action="/driver/trips/%d/delay"] [name="minutes"]', $trip->getId()))->each(static fn (Crawler $i): string => (string) $i->attr('value'));
        self::assertSame(['15', '30', '45', '60'], $offered);
        self::assertStringContainsString('Le trajet est déjà décalé de 5 h : au-delà de 6 h, annulez-le.', $this->pageText());

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d/delay?minutes=30', $trip->getId()));
        $form = $crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), 'Confirmer'))->form();
        $values = $form->getPhpValues();
        $values['minutes'] = '120';
        $this->client->request('POST', $form->getUri(), $values);

        $this->em->clear();
        self::assertSame(300, $this->em->find(Trip::class, $trip->getId())->getDelayMinutes(), '7 h in total is refused');
    }
}
