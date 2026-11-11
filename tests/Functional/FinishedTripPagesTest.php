<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;

/**
 * A trip that is over is a summary, not something to organise (approved mockups:
 * "Trajet effectué" for the driver and the passenger, "Trajet annulé").
 * The default trip is on Thursday 12 November 2026; "now" is the 13th, inside the review window.
 */
final class FinishedTripPagesTest extends WebFlowTestCase
{
    private User $karim;
    private User $mehdi;
    private User $salma;
    private Trip $trip;
    private Booking $mehdisBooking;
    private Booking $salmasBooking;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-11-13 10:00');

        $this->karim = $this->db->user('Karim')->setPhone('0600000001');
        $this->mehdi = $this->db->user('Mehdi')->setPhone('0600000002');
        $this->salma = $this->db->user('Salma')->setPhone('0600000003');
        $this->trip = $this->db->trip(driver: $this->karim);
        $this->trip->setStatus(TripStatus::Completed);
        $this->mehdisBooking = $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed, $this->mehdi);
        $this->salmasBooking = $this->db->booking($this->trip, 0, 2, 1, BookingStatus::Completed, $this->salma);
        $this->db->booking($this->trip, 0, 1, 1, BookingStatus::CancelledByPassenger, $this->db->user('Youssef'));
        $this->em->flush();
    }

    private function review(Booking $booking, User $author, User $target, int $rating, string $comment): void
    {
        $this->em->persist((new Review())->setBooking($booking)->setAuthor($author)->setTarget($target)->setRating($rating)->setComment($comment));
        $this->em->flush();
    }

    private function driverPage(): void
    {
        $this->login($this->karim);
        $this->client->request('GET', sprintf('/driver/trips/%d', $this->trip->getId()));
        self::assertResponseIsSuccessful();
    }

    private function reviewLink(Booking $booking): string
    {
        return sprintf('a[href="/bookings/%d/review"]', $booking->getId());
    }

    // ---- driver ---------------------------------------------------------------------------

    public function testTheDriverSeesASummaryInsteadOfSeatCounters(): void
    {
        $this->driverPage();

        $text = $this->pageText();
        self::assertStringContainsString('Trajet effectué le jeudi 12 novembre', $text);
        self::assertStringContainsString('passagers transportés', $text);
        self::assertStringContainsString('200 MAD', $text, 'two completed bookings of 100 MAD');
        self::assertStringContainsString('Mehdi T.', $text);
        self::assertStringContainsString('Salma T.', $text);
        self::assertSelectorNotExists('.leg-seats');
        self::assertStringNotContainsString('Annuler le trajet', $text);
    }

    public function testNoPhoneNumberAfterTheTrip(): void
    {
        $this->driverPage();

        self::assertStringNotContainsString('0600000002', $this->pageText());
        self::assertStringNotContainsString('0600000003', $this->pageText());
        self::assertSelectorNotExists('a[href^="tel:"]');
    }

    public function testTheMapOfAFinishedTripHasNoOccupancyColours(): void
    {
        $this->driverPage();

        $map = $this->client->getCrawler()->filter('[data-controller~="trip-map"]');
        self::assertCount(1, $map);
        $data = json_decode((string) $map->attr('data-trip-map-data-value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([null, null, null], array_column($data['legs'], 'seatsTaken'));
    }

    public function testTheDriverSeesWhatPassengersSaidAndTheTripRating(): void
    {
        $this->review($this->mehdisBooking, $this->mehdi, $this->karim, 5, 'Ponctuel et prudent.');
        $this->review($this->salmasBooking, $this->salma, $this->karim, 4, 'Voiture propre.');

        $this->driverPage();

        $text = $this->pageText();
        self::assertStringContainsString('Ponctuel et prudent.', $text);
        self::assertStringContainsString('Voiture propre.', $text);
        self::assertStringContainsString('4,5', $text, 'rating of this trip');
    }

    public function testTheDriverReviewsAPassengerFromTheFinishedTrip(): void
    {
        $this->driverPage();
        self::assertSelectorTextContains($this->reviewLink($this->mehdisBooking), 'Noter Mehdi');

        $crawler = $this->client->request('GET', sprintf('/bookings/%d/review', $this->mehdisBooking->getId()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Publier mon avis')->form([
            'review[rating]' => 4,
            'review[comment]' => 'Passager agréable et à l\'heure.',
        ]));

        self::assertResponseRedirects(sprintf('/driver/trips/%d', $this->trip->getId()), null, 'back to the trip, not to the profile');
        $this->client->followRedirect();
        self::assertStringContainsString('Passager agréable et à l\'heure.', $this->pageText());
        self::assertSelectorNotExists($this->reviewLink($this->mehdisBooking));
        self::assertSelectorExists($this->reviewLink($this->salmasBooking), 'Salma can still be reviewed');
    }

    public function testNoReviewLinkOnceTheWindowIsOver(): void
    {
        static::mockTime('2026-12-10 10:00'); // more than 14 days after the drop-off

        $this->driverPage();

        self::assertSelectorNotExists($this->reviewLink($this->mehdisBooking));
    }

    // ---- passenger ------------------------------------------------------------------------

    private function bookingPage(): void
    {
        $this->login($this->salma);
        $this->client->request('GET', sprintf('/bookings/%d', $this->salmasBooking->getId()));
        self::assertResponseIsSuccessful();
    }

    public function testThePassengerSeesAFinishedTripWithoutCancelOrPhone(): void
    {
        $this->review($this->salmasBooking, $this->karim, $this->salma, 4, 'Passagère agréable.');

        $this->bookingPage();

        $text = $this->pageText();
        self::assertStringContainsString('Trajet effectué le jeudi 12 novembre', $text);
        self::assertStringContainsString('Passagère agréable.', $text, "the driver's review of her");
        self::assertStringNotContainsString('Annuler la réservation', $text);
        self::assertStringNotContainsString('0600000001', $text);
        self::assertSelectorExists($this->reviewLink($this->salmasBooking));
        self::assertSelectorExists('a[href="/trips?from=Agadir&to=Marrakech"]', 'Refaire ce trajet');
    }

    public function testAfterReviewingThePassengerSeesTheirReviewInsteadOfTheButton(): void
    {
        $this->bookingPage();

        $crawler = $this->client->request('GET', sprintf('/bookings/%d/review', $this->salmasBooking->getId()));
        $this->client->submit($crawler->selectButton('Publier mon avis')->form([
            'review[rating]' => 5,
            'review[comment]' => 'Très bon conducteur.',
        ]));

        self::assertResponseRedirects(sprintf('/bookings/%d', $this->salmasBooking->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Votre avis', $this->pageText());
        self::assertStringContainsString('Très bon conducteur.', $this->pageText());
        self::assertSelectorNotExists($this->reviewLink($this->salmasBooking));
    }

    // ---- cancelled ------------------------------------------------------------------------

    public function testACancelledTripShowsWhoWasRefundedAndNoMap(): void
    {
        $trip = $this->db->trip(driver: $this->karim);
        $trip->setStatus(TripStatus::Cancelled);
        $booking = $this->db->booking($trip, 0, 2, 1, BookingStatus::CancelledByDriver, $this->mehdi);
        $booking->getPayment()->refund(100);
        $this->em->flush();
        $this->login($this->karim);

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertResponseIsSuccessful();
        $text = $this->pageText();
        self::assertStringContainsString('Trajet annulé', $text);
        self::assertStringContainsString('Mehdi T.', $text);
        self::assertStringContainsString('100 MAD remboursés', $text);
        self::assertSelectorNotExists('[data-controller~="trip-map"]');
        self::assertStringNotContainsString('0600000002', $text);
    }

    public function testACancelledBookingShowsTheRefundAndNoMap(): void
    {
        $trip = $this->db->trip(driver: $this->karim);
        $booking = $this->db->booking($trip, 1, 2, 1, BookingStatus::CancelledByPassenger, $this->salma);
        $booking->getPayment()->refund(50);
        $this->em->flush();
        $this->login($this->salma);

        $this->client->request('GET', sprintf('/bookings/%d', $booking->getId()));

        self::assertResponseIsSuccessful();
        $text = $this->pageText();
        self::assertStringContainsString('Réservation annulée', $text);
        self::assertStringContainsString('50 MAD remboursés', $text);
        self::assertSelectorNotExists('[data-controller~="trip-map"]');
        self::assertSelectorNotExists('a[href$="/review"]');
    }
}
