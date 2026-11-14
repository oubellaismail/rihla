<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;

/**
 * Luggage in the pages (boards « Vos bagages, voyageur », « Le coffre, conducteur »,
 * « Le coffre dans les pages »).
 * Trip: Agadir(0) 08:00 → Essaouira(1) 09:45 → Marrakech(2) 12:00 → Casablanca(3),
 * Dacia Logan 530 L, so 424 L counted. Bags: cabin 40 L, medium 70 L, large 100 L.
 * On the seat page the bags are number inputs bags[cabin], bags[medium], bags[large].
 */
final class LuggageTest extends WebFlowTestCase
{
    private function salma(): User
    {
        $salma = $this->db->user('Salma');
        $this->login($salma);

        return $salma;
    }

    private function seatPage(Trip $trip, int $from = 1, int $to = 2, int $seats = 1): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', sprintf('/trips/%d/seats?from=%d&to=%d&seats=%d', $trip->getId(), $from, $to, $seats));
    }

    private function bookingOf(User $user): ?Booking
    {
        $this->em->clear();

        return $this->em->getRepository(Booking::class)->findOneBy(['passenger' => $user->getId()]);
    }

    // ---- driver: the trunk volume ------------------------------------------------------------

    public function testAVehicleIsAddedWithItsTrunkVolume(): void
    {
        $karim = $this->db->user('Karim');
        $this->login($karim);

        $crawler = $this->client->request('GET', '/account/vehicles');
        self::assertStringContainsString('Volume du coffre', $this->pageText());
        $this->client->submit($crawler->selectButton('Ajouter le véhicule')->form([
            'vehicle[model]' => 'Dacia Logan',
            'vehicle[color]' => 'bleue',
            'vehicle[passengerSeats]' => 4,
            'vehicle[trunkLitres]' => 530,
        ]));

        self::assertResponseRedirects();
        $vehicle = $this->em->getRepository(Vehicle::class)->findOneBy(['owner' => $karim]);
        self::assertSame(530, $vehicle->getTrunkLitres());

        $this->client->followRedirect();
        self::assertStringContainsString('coffre 530 L', $this->pageText());
    }

    public function testAnImpossibleTrunkVolumeIsRefused(): void
    {
        $karim = $this->db->user('Karim');
        $this->login($karim);

        $crawler = $this->client->request('GET', '/account/vehicles');
        $this->client->submit($crawler->selectButton('Ajouter le véhicule')->form([
            'vehicle[model]' => 'Dacia Logan',
            'vehicle[color]' => 'bleue',
            'vehicle[passengerSeats]' => 4,
            'vehicle[trunkLitres]' => 20,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le volume du coffre doit être entre 50 et 3000 litres.', $this->pageText());
        self::assertNull($this->em->getRepository(Vehicle::class)->findOneBy(['owner' => $karim]));
    }

    /** @param int|string $trunk value typed in « Coffre pour ce trajet » ('' = left empty) */
    private function publish(User $karim, Vehicle $vehicle, int|string $trunk): void
    {
        $crawler = $this->client->request('GET', '/trips/new');
        self::assertStringContainsString('Coffre pour ce trajet', $this->pageText());
        $form = $crawler->selectButton('Publier le trajet')->form();
        $values = $form->getPhpValues();
        $values['trip']['vehicle'] = $vehicle->getId();
        $values['trip']['seatsOffered'] = 3;
        $values['trip']['trunkLitres'] = $trunk;
        $values['trip']['stops'] = [
            ['city' => $this->db->city('Agadir')->getId(), 'departureAt' => '2026-11-12T08:00', 'meetingPoint' => 'Gare routière', 'legPriceToNext' => 60],
            ['city' => $this->db->city('Essaouira')->getId(), 'departureAt' => '2026-11-12T09:45', 'meetingPoint' => 'Bab Marrakech', 'legPriceToNext' => ''],
        ];
        $this->client->request('POST', $form->getUri(), $values);
    }

    public function testPublishingKeepsTheWholeTrunkByDefault(): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim, trunkLitres: 530);
        $this->login($karim);

        $this->publish($karim, $vehicle, '');

        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]);
        self::assertNotNull($trip);
        self::assertSame(530, $trip->getTrunkLitres());
    }

    public function testTheDriverCanLowerTheTrunkForOneTrip(): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim, trunkLitres: 530);
        $this->login($karim);

        $this->publish($karim, $vehicle, 300);

        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]);
        self::assertSame(300, $trip->getTrunkLitres());
        $this->em->clear();
        self::assertSame(530, $this->em->find(Vehicle::class, $vehicle->getId())->getTrunkLitres(), 'the vehicle keeps its volume');
    }

    public function testATripCannotHaveMoreTrunkThanItsVehicle(): void
    {
        $karim = $this->db->user('Karim');
        $vehicle = $this->db->vehicle($karim, trunkLitres: 530);
        $this->login($karim);

        $this->publish($karim, $vehicle, 600);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le coffre de votre véhicule ne fait que 530 L.', $this->pageText());
        self::assertNull($this->em->getRepository(Trip::class)->findOneBy(['driver' => $karim]));
    }

    // ---- passenger: what is shown before booking ---------------------------------------------

    public function testSearchResultsTellWhatStillFitsInTheTrunk(): void
    {
        $roomy = $this->db->trip();
        $full = $this->db->trip(driver: $this->db->user('Hamid'));
        $this->db->booking($full, 0, 3, 2, seatCodes: ['A1', 'B1'], bags: ['large' => 4]); // 400 of 424 L

        $this->client->request('GET', '/trips?from=Essaouira&to=Marrakech&date=2026-11-12&seats=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Coffre : place pour 4 grandes valises', $this->pageText());
        self::assertStringContainsString('Coffre plein : petits sacs seulement', $this->pageText());
    }

    public function testTheTripPageShowsTheTrunkForThePortion(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, bags: ['large' => 1, 'medium' => 1]); // 170 L

        $this->client->request('GET', sprintf('/trips/%d?from=1&to=2', $trip->getId()));

        self::assertStringContainsString('coffre 530 L', $this->pageText());
        self::assertStringContainsString('place pour 2 grandes valises', $this->pageText());
    }

    public function testTheSeatPageAsksForTheBags(): void
    {
        $trip = $this->db->trip();
        $this->salma();

        $this->seatPage($trip, seats: 2);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Vos bagages', $this->pageText());
        foreach (['cabin', 'medium', 'large'] as $size) {
            self::assertSelectorExists(sprintf('input[type="number"][name="bags[%s]"][min="0"][max="4"][value="0"]', $size), "2 places, up to 4 bags of size $size");
        }
        foreach (['Bagage cabine', 'Valise moyenne', 'Grande valise', 'Sac à main, petit sac à dos'] as $label) {
            self::assertStringContainsString($label, $this->pageText());
        }
        self::assertStringContainsString('place pour 4 grandes valises', $this->pageText());
    }

    // ---- booking with bags -------------------------------------------------------------------

    public function testBookingWithBags(): void
    {
        $trip = $this->db->trip();
        $salma = $this->salma();

        $crawler = $this->seatPage($trip);
        $form = $crawler->selectButton('Pas de préférence, choisir pour moi')->form(['bags[medium]' => 1]);
        $this->client->submit($form);

        $booking = $this->bookingOf($salma);
        self::assertNotNull($booking);
        self::assertSame(['cabin' => 0, 'medium' => 1, 'large' => 0], $booking->getBags());
        self::assertSame(65, $booking->getTotalPrice(), 'bags are free');

        $this->client->followRedirect();
        self::assertStringContainsString('Bagages', $this->pageText());
        self::assertStringContainsString('1 valise moyenne', $this->pageText());
    }

    public function testATrunkFilledMeanwhileShowsTheSeatPageAgainWithTheChoicesKept(): void
    {
        $trip = $this->db->trip();
        $salma = $this->salma();
        $crawler = $this->seatPage($trip);
        $form = $crawler->selectButton('Réserver')->form(['bags[large]' => 1]);
        foreach ($form['seatCodes'] as $box) {
            if (\in_array('B1', $box->availableOptionValues(), true)) {
                $box->tick();
            }
        }

        $this->db->booking($trip, 0, 3, 2, passenger: $this->db->user('Rachid'), seatCodes: ['A1', 'B3'], bags: ['large' => 3, 'medium' => 1]); // 370 L: 54 L left
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->bookingOf($salma));
        self::assertSelectorTextContains('h1', 'Choisissez votre place');
        self::assertStringContainsString("Le coffre s'est rempli entre-temps", $this->pageText());
        self::assertStringContainsString('Vos bagages ne passent plus sur cette portion : il reste de la place pour un bagage cabine.', $this->pageText());
        self::assertSelectorExists('input[name="seatCodes[]"][value="B1"][checked]', 'the seat stays chosen');
        self::assertSelectorExists('input[name="bags[large]"][value="1"]', 'the bags stay as typed');

        $form = $this->client->getCrawler()->selectButton('Réserver')->form(['bags[large]' => 0, 'bags[cabin]' => 1]);
        $this->client->submit($form);
        self::assertSame(['B1'], $this->bookingOf($salma)->getSeatCodes());
        self::assertSame(40, $this->bookingOf($salma)->getLuggageLitres());
    }

    public function testTooManyBagsGoBackToTheSeatPageWithTheReason(): void
    {
        $trip = $this->db->trip();
        $this->salma();

        $crawler = $this->seatPage($trip);
        $this->client->submit($crawler->selectButton('Pas de préférence, choisir pour moi')->form(['bags[cabin]' => 3]));

        self::assertStringContainsString('Au plus 2 bagages comptés par voyageur.', $this->client->getResponse()->isRedirect()
            ? $this->client->followRedirect()->filter('body')->text()
            : $this->pageText());
    }

    // ---- driver: stop by stop ----------------------------------------------------------------

    public function testTheDriverSeesWhatToLoadAndUnloadAtEachStop(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $this->db->booking($trip, 0, 3, passenger: $this->db->user('Rachid'), seatCodes: ['B1'], bags: ['large' => 1]);
        $this->db->booking($trip, 1, 2, passenger: $this->db->user('Salma'), seatCodes: ['B3'], bags: ['medium' => 1]);
        $this->db->booking($trip, 0, 1, passenger: $this->db->user('Mehdi'), seatCodes: ['A1']); // no bag
        $this->login($karim);

        $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertResponseIsSuccessful();
        $text = $this->pageText();
        self::assertStringContainsString('Le coffre, arrêt par arrêt', $text);
        self::assertStringContainsString('Rachid : 1 grande valise', $text);
        self::assertStringContainsString('Salma : 1 valise moyenne', $text);
        self::assertStringContainsString('100 L sur 424 L', $text, 'Agadir → Essaouira');
        self::assertStringContainsString('170 L sur 424 L', $text, 'Essaouira → Marrakech');
        self::assertStringNotContainsString('Mehdi : ', $text, 'nothing to load for Mehdi');
    }

    public function testTheDriverGetsAChecklistInTheOrderItIsDone(): void
    {
        $karim = $this->db->user('Karim');
        $trip = $this->db->trip(driver: $karim);
        $this->db->booking($trip, 0, 1, passenger: $this->db->user('Mehdi'), seatCodes: ['A1'], bags: ['large' => 1]);
        $this->db->booking($trip, 0, 3, passenger: $this->db->user('Rachid'), seatCodes: ['B1'], bags: ['large' => 1]);
        $this->db->booking($trip, 1, 2, passenger: $this->db->user('Salma'), seatCodes: ['A1'], bags: ['medium' => 1]);
        $this->login($karim);

        $crawler = $this->client->request('GET', sprintf('/driver/trips/%d', $trip->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.driver-trip__side .trunk-plan', 'the trunk sits in the side column');
        $agadir = $crawler->filter('.trunk-stop')->eq(0)->text();
        self::assertStringContainsString('Ranger', $agadir);
        self::assertStringNotContainsString('Rendre', $agadir);
        self::assertStringContainsString('descend à Casablanca, à mettre au fond', $agadir);
        self::assertLessThan(strpos($agadir, 'Mehdi'), strpos($agadir, 'Rachid'), 'furthest first');
        self::assertStringContainsString("Coffre à moitié plein jusqu'à Essaouira", $agadir);

        $essaouira = $crawler->filter('.trunk-stop')->eq(1)->text();
        self::assertLessThan(strpos($essaouira, 'Puis ranger'), strpos($essaouira, "Rendre d'abord"), 'hand back before putting in');
        self::assertStringContainsString('descend à Marrakech', $essaouira);

        self::assertCount(6, $crawler->filter('.trunk-plan input[type="checkbox"]'), 'one box per line: 2 at Agadir, 2 at Essaouira, 1 at Marrakech, 1 at Casablanca');
    }
}
