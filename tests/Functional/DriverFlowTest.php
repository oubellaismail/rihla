<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;

/**
 * What a driver does in the browser: add a vehicle, publish a trip with stops,
 * see who rides on each leg, cancel the trip. Also the account page.
 */
final class DriverFlowTest extends WebFlowTestCase
{
    public function testADriverWithoutVehicleIsAskedToAddOneBeforePublishing(): void
    {
        $this->login($this->db->user('Karim'));

        $this->client->request('GET', '/trips/new');

        self::assertResponseRedirects('/account/vehicles');
    }

    public function testAddingAVehicle(): void
    {
        $karim = $this->db->user('Karim');
        $this->login($karim);

        $crawler = $this->client->request('GET', '/account/vehicles');
        $this->client->submit($crawler->selectButton('Ajouter le véhicule')->form([
            'vehicle[model]' => 'Dacia Lodgy',
            'vehicle[color]' => 'grise',
            'vehicle[passengerSeats]' => 6,
        ]));

        self::assertResponseRedirects();
        $vehicle = $this->em->getRepository(Vehicle::class)->findOneBy(['owner' => $karim]);
        self::assertNotNull($vehicle);
        self::assertSame(6, $vehicle->getPassengerSeats());
    }

    public function testPublishingATripWithAnIntermediateStop(): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim);
        $agadir = $this->db->city('Agadir');
        $essaouira = $this->db->city('Essaouira');
        $marrakech = $this->db->city('Marrakech');
        $this->login($karim);

        $crawler = $this->client->request('GET', '/trips/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Publier le trajet')->form();
        $values = $form->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        $values['trip']['stops'] = [
            ['city' => $agadir->getId(), 'departureAt' => '2026-11-12T08:00', 'meetingPoint' => 'Gare routière', 'legPriceToNext' => 60],
            ['city' => $essaouira->getId(), 'departureAt' => '2026-11-12T09:45', 'meetingPoint' => 'Bab Marrakech', 'legPriceToNext' => 65],
            ['city' => $marrakech->getId(), 'departureAt' => '2026-11-12T12:00', 'meetingPoint' => 'Gare ONCF', 'legPriceToNext' => ''],
        ];
        $this->client->request('POST', $form->getUri(), $values);

        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]);
        self::assertNotNull($trip, 'the trip is saved');
        self::assertCount(3, $trip->getStops());
        self::assertSame('Essaouira', $trip->getStops()[1]->getCity()->getName());
        self::assertResponseRedirects(sprintf('/driver/trips/%d', $trip->getId()));
    }

    public function testAnInvalidTripIsShownBackWithTheReason(): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim);
        $agadir = $this->db->city('Agadir');
        $essaouira = $this->db->city('Essaouira');
        $this->login($karim);

        $crawler = $this->client->request('GET', '/trips/new');
        $form = $crawler->selectButton('Publier le trajet')->form();
        $values = $form->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        $values['trip']['stops'] = [
            ['city' => $agadir->getId(), 'departureAt' => '2026-11-12T08:00', 'meetingPoint' => 'Gare routière', 'legPriceToNext' => 60],
            ['city' => $essaouira->getId(), 'departureAt' => '2026-11-12T07:00', 'meetingPoint' => 'Bab Marrakech', 'legPriceToNext' => ''],
        ];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Les horaires des arrêts doivent être strictement croissants.', $this->pageText());
        self::assertNull($this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]));
    }

    public function testAStopWithoutCityOrTimeIsAFormErrorNotACrash(): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim);
        $agadir = $this->db->city('Agadir');
        $this->login($karim);

        $crawler = $this->client->request('GET', '/trips/new');
        $form = $crawler->selectButton('Publier le trajet')->form();
        $values = $form->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        $values['trip']['stops'] = [
            ['city' => $agadir->getId(), 'departureAt' => '2026-11-12T08:00', 'meetingPoint' => 'Gare routière', 'legPriceToNext' => 60],
            ['city' => '', 'departureAt' => '', 'meetingPoint' => '', 'legPriceToNext' => ''],
        ];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]));
    }

    public function testTheDriverSeesHisTripsAndThePassengersOfEachLeg(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $salma = $this->db->user('Salma');
        $this->db->booking($trip, 1, 2, 1, BookingStatus::Confirmed, $salma);
        $this->login($karim);

        $this->client->request('GET', '/driver/trips');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href="/driver/trips/%d"]', $trip->getId()));

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Salma T.', $this->pageText());
    }

    public function testOnlyTheDriverSeesThePassengerList(): void
    {
        $trip = $this->db->trip();
        $this->login($this->db->user('Omar'));

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCancellingTheTripCancelsAndRefundsEveryBooking(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $booking = $this->db->booking($trip, 1, 2);
        $this->login($karim);

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));
        $this->client->submit($crawler->selectButton('Annuler le trajet')->form());

        self::assertResponseRedirects(sprintf('/driver/trips/%d', $trip->getId()));
        $trip = $this->reload($trip);
        $booking = $this->reload($booking);
        self::assertSame(TripStatus::Cancelled, $trip->getStatus());
        self::assertSame(BookingStatus::CancelledByDriver, $booking->getStatus());
    }

    public function testUpdatingMyAccount(): void
    {
        $karim = $this->db->user('Karim');
        $this->login($karim);

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'account[phone]' => '0611223344',
        ]));

        self::assertResponseRedirects('/account');
        $karim = $this->reload($karim);
        self::assertSame('0611223344', $karim->getPhone());
    }
}
