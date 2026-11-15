<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Payment;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The back-office (/admin, EasyAdmin). "Now" is 2026-11-10 10:00; the default trip leaves on 2026-11-12.
 *
 * What the app owns (trips, stops, bookings, payments, vehicles) is read-only here: changing it by hand would skip
 * the app's rules. One action changes a trip: « Annuler le trajet », the same cancellation as the driver's, with full
 * refunds. Reviews can be deleted (moderation). Users can be edited, with the admin role as a choice; an admin cannot
 * drop their own. Cities can be managed, but a city used by a stop cannot be deleted.
 */
final class AdminTest extends WebFlowTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->db->user('Admin')->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
    }

    private function asAdmin(): void
    {
        $this->login($this->reload($this->admin));
    }

    /** A trip with a paid booking and a review: one row in every table. */
    private function everything(): Booking
    {
        $trip = $this->db->trip();
        $booking = $this->db->booking($trip, 0, 1, passenger: $this->db->user('Salma'));
        $this->em->persist((new Review())->setBooking($booking)->setAuthor($booking->getPassenger())->setTarget($trip->getDriver())->setRating(5)->setComment('Très bien.'));
        $this->em->flush();

        return $booking;
    }

    /** CSRF token EasyAdmin puts in its delete confirmation form. */
    private function deleteToken(string $crud): string
    {
        $crawler = $this->client->request('GET', '/admin/'.$crud);

        return (string) $crawler->filter('input[name="token"]')->attr('value');
    }

    private function delete(string $crud, int $id): void
    {
        $token = $this->deleteToken($crud);
        $this->client->request('POST', sprintf('/admin/%s/%d/delete', $crud, $id), ['token' => $token]);
    }

    private function cardText(Crawler $crawler, string $label): string
    {
        $card = $crawler->filter('.card')->reduce(static fn (Crawler $c): bool => str_contains($c->text(), $label));
        self::assertCount(1, $card, $label);

        return preg_replace('/\s+/u', ' ', $card->text());
    }

    // ---- access -----------------------------------------------------------------------------------

    public function testAVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testAMemberIsRefused(): void
    {
        $this->login($this->db->user('Karim'));

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    // ---- every page opens -------------------------------------------------------------------------

    public function testEveryListAndDetailPageOpens(): void
    {
        $booking = $this->everything();
        $booking->setSeatCodes(['A1']);
        $this->em->flush();
        $trip = $booking->getTrip();
        $ids = [
            'trip' => $trip->getId(),
            'trip-stop' => $booking->getFromStop()->getId(),
            'booking' => $booking->getId(),
            'payment' => $booking->getPayment()->getId(),
            'review' => $this->em->getRepository(Review::class)->findOneBy(['booking' => $booking])->getId(),
            'user' => $trip->getDriver()->getId(),
            'vehicle' => $trip->getVehicle()->getId(),
            'city' => $booking->getFromStop()->getCity()->getId(),
        ];
        $this->asAdmin();

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        foreach ($ids as $crud => $id) {
            $this->client->request('GET', '/admin/'.$crud);
            self::assertResponseIsSuccessful('/admin/'.$crud);
            $this->client->request('GET', sprintf('/admin/%s/%d', $crud, $id));
            self::assertResponseIsSuccessful(sprintf('/admin/%s/%d', $crud, $id));
        }
    }

    public function testABookingShowsItsSeats(): void
    {
        $booking = $this->everything();
        $booking->setSeatCodes(['A1', 'B2']);
        $this->em->flush();
        $this->asAdmin();

        $this->client->request('GET', sprintf('/admin/booking/%d', $booking->getId()));

        self::assertStringContainsString('A1, B2', $this->pageText());
    }

    // ---- read-only tables -------------------------------------------------------------------------

    public function testWhatTheAppOwnsCannotBeCreatedOrEdited(): void
    {
        $booking = $this->everything();
        $trip = $booking->getTrip();
        $review = $this->em->getRepository(Review::class)->findOneBy(['booking' => $booking]);
        $this->asAdmin();

        $urls = [
            '/admin/trip/new',
            sprintf('/admin/trip/%d/edit', $trip->getId()),
            '/admin/trip-stop/new',
            sprintf('/admin/trip-stop/%d/edit', $booking->getFromStop()->getId()),
            '/admin/booking/new',
            sprintf('/admin/booking/%d/edit', $booking->getId()),
            '/admin/payment/new',
            sprintf('/admin/payment/%d/edit', $booking->getPayment()->getId()),
            '/admin/review/new',
            sprintf('/admin/review/%d/edit', $review->getId()),
            '/admin/user/new',
            '/admin/vehicle/new',
            sprintf('/admin/vehicle/%d/edit', $trip->getVehicle()->getId()),
        ];
        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testWhatTheAppOwnsCannotBeDeleted(): void
    {
        $booking = $this->everything();
        $trip = $booking->getTrip();
        $this->asAdmin();

        $rows = [
            'trip' => [Trip::class, $trip->getId()],
            'trip-stop' => [TripStop::class, $booking->getFromStop()->getId()],
            'booking' => [Booking::class, $booking->getId()],
            'payment' => [Payment::class, $booking->getPayment()->getId()],
            'user' => [User::class, $trip->getDriver()->getId()],
            'vehicle' => [Vehicle::class, $trip->getVehicle()->getId()],
        ];
        foreach ($rows as $crud => [$class, $id]) {
            $this->delete($crud, $id);
            self::assertResponseStatusCodeSame(403, $crud);
            $this->em->clear();
            self::assertNotNull($this->em->find($class, $id), $crud.' is kept');
        }
    }

    public function testTheListsOfferNoCreateButtonForWhatTheAppOwns(): void
    {
        $this->asAdmin();

        foreach (['trip', 'trip-stop', 'booking', 'payment', 'review', 'user', 'vehicle'] as $crud) {
            $crawler = $this->client->request('GET', '/admin/'.$crud);
            self::assertCount(0, $crawler->filter(sprintf('a[href$="/admin/%s/new"]', $crud)), $crud);
        }
        $crawler = $this->client->request('GET', '/admin/city');
        self::assertCount(1, $crawler->filter('a[href$="/admin/city/new"]'), 'cities can still be added');
    }

    // ---- cancel a trip ----------------------------------------------------------------------------

    public function testCancellingATripRefundsItsPassengers(): void
    {
        $booking = $this->everything();
        $trip = $booking->getTrip();
        $other = $this->db->booking($trip, 1, 3, 2, passenger: $this->db->user('Imane'));
        $this->asAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/trip/%d', $trip->getId()));
        $form = $crawler->filter('form[method="post"]')->reduce(static fn (Crawler $f): bool => str_contains($f->text(), 'Annuler le trajet'));
        self::assertCount(1, $form, 'a POST form, not a link');
        $this->client->submit($form->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('Trajet annulé. 2 passagers remboursés intégralement.', $this->pageText());
        $this->em->clear();
        self::assertSame(TripStatus::Cancelled, $this->em->find(Trip::class, $trip->getId())->getStatus());
        foreach ([$booking, $other] as $b) {
            $saved = $this->em->find(Booking::class, $b->getId());
            self::assertSame(BookingStatus::CancelledByDriver, $saved->getStatus());
            self::assertSame($saved->getPayment()->getAmount(), $saved->getPayment()->getRefundedAmount());
        }
    }

    public function testAFinishedOrCancelledTripCannotBeCancelled(): void
    {
        $past = $this->db->trip(day: '2026-11-01');
        $past->setStatus(TripStatus::Completed);
        $cancelled = $this->db->trip();
        $cancelled->setStatus(TripStatus::Cancelled);
        $this->em->flush();
        $this->asAdmin();

        foreach ([$past, $cancelled] as $trip) {
            $this->client->request('GET', sprintf('/admin/trip/%d', $trip->getId()));
            self::assertStringNotContainsString('Annuler le trajet', $this->pageText());
        }
    }

    // ---- reviews: moderation ----------------------------------------------------------------------

    public function testAReviewCanBeDeleted(): void
    {
        $booking = $this->everything();
        $id = $this->em->getRepository(Review::class)->findOneBy(['booking' => $booking])->getId();
        $this->asAdmin();

        $this->delete('review', $id);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->find(Review::class, $id));
    }

    // ---- users ------------------------------------------------------------------------------------

    public function testRolesAreShownInFrench(): void
    {
        $this->db->user('Karim');
        $this->em->flush();
        $this->asAdmin();

        $this->client->request('GET', '/admin/user');

        self::assertStringContainsString('Administrateur', $this->pageText());
        self::assertStringContainsString('Utilisateur', $this->pageText());
        self::assertStringNotContainsString('ROLE_', $this->pageText());
    }

    public function testTheRoleIsAChoiceNotFreeText(): void
    {
        $karim = $this->db->user('Karim');
        $this->em->flush();
        $this->asAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/user/%d/edit', $karim->getId()));

        self::assertCount(0, $crawler->filter('input[type="text"][name^="User[roles]"]'), 'no free text');
        self::assertStringContainsString('Administrateur', $crawler->filter('form')->text());

        $form = $crawler->filter('form[name="User"]')->form();
        $values = $form->getPhpValues();
        $values['User']['roles'] = ['ROLE_ADMIN'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        self::assertContains('ROLE_ADMIN', $this->reload($karim)->getRoles());
    }

    public function testAnAdminCannotDropTheirOwnRole(): void
    {
        $this->asAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/user/%d/edit', $this->admin->getId()));
        $form = $crawler->filter('form[name="User"]')->form();
        $values = $form->getPhpValues();
        unset($values['User']['roles']);
        $this->client->request('POST', $form->getUri(), $values);

        self::assertStringContainsString("Vous ne pouvez pas retirer votre propre rôle d'administrateur.", $this->pageText());
        self::assertContains('ROLE_ADMIN', $this->reload($this->admin)->getRoles());
    }

    // ---- cities -----------------------------------------------------------------------------------

    public function testACityUsedByATripCannotBeDeleted(): void
    {
        $trip = $this->db->trip();
        $id = $trip->getStops()->first()->getCity()->getId();
        $this->asAdmin();

        $this->delete('city', $id);
        $this->client->followRedirect();

        self::assertStringContainsString('Cette ville est utilisée par des trajets : elle ne peut pas être supprimée.', $this->pageText());
        $this->em->clear();
        self::assertNotNull($this->em->find(City::class, $id));
    }

    public function testAnUnusedCityCanBeDeleted(): void
    {
        $city = $this->db->city('Ifrane');
        $this->em->flush();
        $id = $city->getId();
        $this->asAdmin();

        $this->delete('city', $id);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->find(City::class, $id));
    }

    // ---- French labels, vehicles, dashboard -------------------------------------------------------

    public function testStatusesAreInFrench(): void
    {
        $this->everything();
        $this->asAdmin();

        foreach (['trip' => 'Publié', 'booking' => 'Confirmée', 'payment' => 'Payé'] as $crud => $label) {
            $this->client->request('GET', '/admin/'.$crud);
            self::assertStringContainsString($label, $this->pageText(), $crud);
        }
        foreach (['Published', 'Confirmed', 'Paid'] as $english) {
            foreach (['trip', 'booking', 'payment'] as $crud) {
                $this->client->request('GET', '/admin/'.$crud);
                self::assertStringNotContainsString($english, $this->pageText(), $crud);
            }
        }
    }

    public function testAnArchivedCarIsMarkedRetire(): void
    {
        $active = $this->db->vehicle($this->db->user('Karim'));
        $archived = $this->db->vehicle($this->db->user('Nadia'))->setModel('Peugeot 208')->setArchivedAt(new \DateTimeImmutable('2026-11-05'));
        $this->em->flush();
        $this->asAdmin();

        $crawler = $this->client->request('GET', '/admin/vehicle');

        $row = static fn (string $model): string => $crawler->filter('tbody tr')->reduce(static fn (Crawler $tr): bool => str_contains($tr->text(), $model))->text();
        self::assertStringContainsString('Retiré', $row('Peugeot 208'));
        self::assertStringContainsString('Actif', $row('Renault Kangoo'));
    }

    public function testTheDashboardShowsTheDaysActivityAndTheMoney(): void
    {
        $today = $this->db->trip(stops: [['Agadir', '18:00', 40], ['Tiznit', '19:30', null]], day: '2026-11-10');
        $this->db->booking($today, 0, 1); // paid 100
        $later = $this->db->trip(); // 2026-11-12
        $refunded = $this->db->booking($later, 0, 1, 2); // paid 200
        $refunded->getPayment()->refund(150);
        $cancelled = $this->db->trip();
        $cancelled->setStatus(TripStatus::Cancelled);
        $this->em->flush();
        $this->asAdmin();

        $crawler = $this->client->request('GET', '/admin');

        self::assertStringContainsString("Départs aujourd'hui 1", $this->cardText($crawler, "Départs aujourd'hui"));
        self::assertStringContainsString('Trajets annulés 1', $this->cardText($crawler, 'Trajets annulés'));
        self::assertStringContainsString('Montant encaissé (MAD) 300', $this->cardText($crawler, 'Montant encaissé (MAD)'));
        self::assertStringContainsString('Montant remboursé (MAD) 150', $this->cardText($crawler, 'Montant remboursé (MAD)'));
    }
}
