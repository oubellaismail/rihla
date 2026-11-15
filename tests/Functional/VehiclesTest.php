<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\TripStatus;
use Symfony\Component\DomCrawler\Crawler;

/**
 * « Mes véhicules »: a driver edits a car, and removes it.
 * "Now" is 2026-11-10 10:00; the default test trip leaves on 2026-11-12 (upcoming).
 *
 * Removing a car: never used → deleted; used only by past or cancelled trips → archived (hidden from the list
 * and from the publish form, still shown on the old trips); used by an upcoming trip → refused.
 */
final class VehiclesTest extends WebFlowTestCase
{
    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->karim = $this->db->user('Karim');
    }

    private function page(): Crawler
    {
        $this->login($this->karim);

        return $this->client->request('GET', '/account/vehicles');
    }

    /** The list item of a vehicle on « Mes véhicules ». */
    private function item(Crawler $crawler, string $model): Crawler
    {
        return $crawler->filter('.vehicle-list > li')->reduce(static fn (Crawler $li): bool => str_contains($li->text(), $model));
    }

    private function remove(Vehicle $vehicle): void
    {
        $crawler = $this->page();
        $form = $crawler->filter(sprintf('form[action="/account/vehicles/%d/delete"]', $vehicle->getId()))->form();
        $this->client->submit($form);
    }

    private function vehicleWithTripOn(string $day, TripStatus $status = TripStatus::Published): Vehicle
    {
        $trip = $this->db->trip(driver: $this->karim, day: $day);
        $trip->setStatus($status);
        $trip->getVehicle()->setModel('Dacia Logan');

        return $trip->getVehicle();
    }

    // ---- edit -------------------------------------------------------------------------------------

    public function testEveryCarHasAnEditLink(): void
    {
        $vehicle = $this->db->vehicle($this->karim);

        $crawler = $this->page();

        self::assertCount(1, $this->item($crawler, 'Renault Kangoo')->filter(sprintf('a[href="/account/vehicles/%d/edit"]', $vehicle->getId())));
        self::assertStringContainsString('Modifier', $this->item($crawler, 'Renault Kangoo')->text());
    }

    public function testTheEditFormOpensInsideTheCarCardFilledIn(): void
    {
        $vehicle = $this->db->vehicle($this->karim, passengerSeats: 3, trunkLitres: 420);
        $this->login($this->karim);

        $crawler = $this->client->request('GET', sprintf('/account/vehicles/%d/edit', $vehicle->getId()));

        self::assertResponseIsSuccessful();
        $card = $this->item($crawler, 'Renault Kangoo');
        self::assertCount(1, $card->filter('form[name="vehicle"]'), 'the form sits in the card of that car');
        self::assertSame('Renault Kangoo', $card->filter('input[name="vehicle[model]"]')->attr('value'));
        self::assertSame('grise', $card->filter('input[name="vehicle[color]"]')->attr('value'));
        self::assertSame('420', $card->filter('input[name="vehicle[trunkLitres]"]')->attr('value'));
        self::assertStringContainsString('Les trajets déjà publiés gardent leurs places et leur coffre.', $card->text());
        self::assertCount(1, $card->filter('a[href="/account/vehicles"]'), 'Annuler goes back to the list');
    }

    public function testSavingTheEditUpdatesTheCar(): void
    {
        $vehicle = $this->db->vehicle($this->karim);
        $this->login($this->karim);

        $crawler = $this->client->request('GET', sprintf('/account/vehicles/%d/edit', $vehicle->getId()));
        $this->client->submit($crawler->filter('form[name="vehicle"]')->form([
            'vehicle[model]' => 'Renault Kangoo',
            'vehicle[color]' => 'blanche',
            'vehicle[passengerSeats]' => 5,
            'vehicle[trunkLitres]' => 660,
        ]));

        self::assertResponseRedirects('/account/vehicles');
        $this->client->followRedirect();
        self::assertStringContainsString('Votre véhicule est modifié.', $this->pageText());
        $saved = $this->reload($vehicle);
        self::assertSame('blanche', $saved->getColor());
        self::assertSame(5, $saved->getPassengerSeats());
        self::assertSame(660, $saved->getTrunkLitres());
    }

    public function testAnInvalidEditShowsTheErrorAndKeepsTheCar(): void
    {
        $vehicle = $this->db->vehicle($this->karim);
        $this->login($this->karim);

        $crawler = $this->client->request('GET', sprintf('/account/vehicles/%d/edit', $vehicle->getId()));
        $this->client->submit($crawler->filter('form[name="vehicle"]')->form(['vehicle[trunkLitres]' => 10]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le volume du coffre doit être entre 50 et 3000 litres.', $this->pageText());
        self::assertSame(530, $this->reload($vehicle)->getTrunkLitres());
    }

    public function testEditingKeepsTheSeatsAndTrunkOfPublishedTrips(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-12');
        $this->login($this->karim);

        $crawler = $this->client->request('GET', sprintf('/account/vehicles/%d/edit', $vehicle->getId()));
        $this->client->submit($crawler->filter('form[name="vehicle"]')->form(['vehicle[trunkLitres]' => 200, 'vehicle[passengerSeats]' => 2]));

        $trip = $this->em->getRepository(Trip::class)->findOneBy(['vehicle' => $vehicle->getId()]);
        self::assertSame(3, $trip->getSeatsOffered());
        self::assertSame(530, $trip->getTrunkLitres());
    }

    public function testNobodyElseCanEditTheCar(): void
    {
        $vehicle = $this->db->vehicle($this->db->user('Imane'));
        $this->login($this->karim);

        $this->client->request('GET', sprintf('/account/vehicles/%d/edit', $vehicle->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    // ---- remove -----------------------------------------------------------------------------------

    public function testACarNeverUsedIsDeleted(): void
    {
        $vehicle = $this->db->vehicle($this->karim);

        $button = $this->item($this->page(), 'Renault Kangoo')->filter('form button');
        self::assertStringContainsString('Supprimer', $button->text());

        $id = $vehicle->getId();
        $this->remove($vehicle);

        self::assertResponseRedirects('/account/vehicles');
        $this->client->followRedirect();
        self::assertStringContainsString('Le véhicule est supprimé.', $this->pageText());
        $this->em->clear();
        self::assertNull($this->em->find(Vehicle::class, $id));
    }

    public function testACarUsedOnlyInThePastIsArchived(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-01');

        $item = $this->item($this->page(), 'Dacia Logan');
        self::assertStringContainsString('Retirer', $item->filter('form button')->text());
        self::assertStringContainsString('Elle ne sera plus proposée pour vos prochains trajets.', (string) $item->filter('form')->attr('data-confirm'));

        $this->remove($vehicle);

        self::assertResponseRedirects('/account/vehicles');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Le véhicule est retiré.', $this->pageText());
        self::assertCount(0, $this->item($crawler, 'Dacia Logan'), 'gone from the list');
        self::assertNotNull($this->reload($vehicle)->getArchivedAt(), 'kept for the old trips');
    }

    public function testACarOfACancelledTripCanBeArchived(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-12', TripStatus::Cancelled);

        $this->remove($vehicle);

        self::assertNotNull($this->reload($vehicle)->getArchivedAt());
    }

    public function testACarOnAnUpcomingTripCannotBeRemoved(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-12');

        $item = $this->item($this->page(), 'Dacia Logan');
        self::assertCount(0, $item->filter('form[action$="/delete"]'), 'no remove button');
        self::assertStringContainsString('Trajet prévu le 12 nov.', $item->text());
    }

    public function testRemovingACarOnAnUpcomingTripIsRefusedAnyway(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-01');
        $this->page();
        $form = $this->client->getCrawler()->filter(sprintf('form[action="/account/vehicles/%d/delete"]', $vehicle->getId()))->form();
        // A trip is published with this car between the page load and the click.
        $later = $this->db->trip(driver: $this->karim, day: '2026-11-14');
        $later->setVehicle($this->reload($vehicle));
        $this->em->flush();

        $this->client->submit($form);

        $this->client->followRedirect();
        self::assertStringContainsString('Ce véhicule est prévu pour un trajet à venir. Annulez ou terminez ce trajet avant de le retirer.', $this->pageText());
        self::assertNull($this->reload($vehicle)->getArchivedAt());
    }

    public function testAnArchivedCarIsNotOfferedWhenPublishing(): void
    {
        $archived = $this->vehicleWithTripOn('2026-11-01');
        $this->remove($archived);
        $active = $this->db->vehicle($this->karim);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/trips/new');

        $choices = $crawler->filter('select[name="trip[vehicle]"] option')->each(static fn (Crawler $o): string => (string) $o->attr('value'));
        self::assertContains((string) $active->getId(), $choices);
        self::assertNotContains((string) $archived->getId(), $choices);
    }

    public function testWithOnlyArchivedCarsTheDriverIsAskedToAddOne(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-01');
        $this->remove($vehicle);

        $this->client->request('GET', '/trips/new');

        self::assertResponseRedirects('/account/vehicles');
    }

    public function testAnArchivedCarStillShowsOnItsOldTrip(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-01');
        $trip = $this->em->getRepository(Trip::class)->findOneBy(['vehicle' => $vehicle]);
        $this->remove($vehicle);

        $this->client->request('GET', sprintf('/trips/%d', $trip->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Dacia Logan', $this->pageText());
    }

    public function testAnArchivedCarCannotBeEdited(): void
    {
        $vehicle = $this->vehicleWithTripOn('2026-11-01');
        $this->remove($vehicle);

        $this->client->request('GET', sprintf('/account/vehicles/%d/edit', $vehicle->getId()));

        self::assertResponseStatusCodeSame(404);
    }
}
