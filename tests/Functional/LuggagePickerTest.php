<?php

namespace App\Tests\Functional;

use App\Entity\Trip;

/**
 * The passenger's bag picker on the seat page, without litres
 * (DECISIONS « The passenger's bag picker without litres »).
 * Trip: Agadir(0) → Essaouira(1) → Marrakech(2) → Casablanca(3), Dacia Logan 530 L, 424 L counted.
 * Bags: cabin 40 L, medium 70 L, large 100 L. The page is read as served (before JavaScript).
 */
final class LuggagePickerTest extends WebFlowTestCase
{
    private function seatPage(Trip $trip, int $seats = 1): void
    {
        $this->login($this->db->user('Salma'));
        $this->client->request('GET', sprintf('/trips/%d/seats?from=1&to=2&seats=%d', $trip->getId(), $seats));
        self::assertResponseIsSuccessful();
    }

    private function luggageText(): string
    {
        return $this->client->getCrawler()->filter('.luggage')->text();
    }

    public function testEachSizeShowsASuitcaseAtItsSizeAndNoLitres(): void
    {
        $this->seatPage($this->db->trip());

        foreach (['cabin' => 18, 'medium' => 24, 'large' => 32] as $size => $pixels) {
            self::assertSelectorExists(sprintf('[data-luggage-size="%s"] .luggage__icon svg[width="%d"]', $size, $pixels), "$size suitcase at $pixels px");
        }
        self::assertSelectorNotExists('.trunk-gauge', 'no litre gauge');
        self::assertDoesNotMatchRegularExpression('/\d+\s?L\b/u', $this->luggageText(), 'no litres in the picker');
        self::assertStringNotContainsString('80 %', $this->luggageText());
        self::assertStringContainsString('Au plus 2 bagages par voyageur.', $this->luggageText());
    }

    public function testOneSentenceSaysWhatStillFits(): void
    {
        $this->seatPage($this->db->trip());

        self::assertSelectorTextSame('.luggage__status', "Vous n'emportez rien en soute. Il reste de la place pour 4 grandes valises.");
    }

    public function testARowSaysWhenItsSizeNoLongerFits(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 2, passenger: $this->db->user('Rachid'), seatCodes: ['A1', 'B3'], bags: ['large' => 3, 'medium' => 1]); // 370 L: 54 L left

        $this->seatPage($trip);

        self::assertSelectorTextContains('[data-luggage-size="large"]', 'Plus assez de place pour une grande valise');
        self::assertSelectorTextContains('[data-luggage-size="medium"]', 'Plus assez de place pour une valise moyenne');
        self::assertStringNotContainsString('Plus assez', $this->client->getCrawler()->filter('[data-luggage-size="cabin"]')->text());
        self::assertSelectorExists('input[name="bags[large]"][max="0"]', 'without JavaScript too, nothing that does not fit can be typed');
        self::assertSelectorExists('input[name="bags[medium]"][max="0"]');
        self::assertSelectorExists('input[name="bags[cabin]"][max="1"]', 'one cabin bag fits in 54 L');
        self::assertSelectorTextSame('.luggage__status', "Vous n'emportez rien en soute. Il reste de la place pour un bagage cabine.");
    }

    public function testAFullTrunkSaysSoOnce(): void
    {
        $trip = $this->db->trip();
        $this->db->booking($trip, 0, 3, 2, passenger: $this->db->user('Rachid'), seatCodes: ['A1', 'B3'], bags: ['large' => 4]); // 400 L: 24 L left

        $this->seatPage($trip);

        self::assertSelectorTextSame('.luggage__status', 'Le coffre est plein sur votre portion.');
        foreach (['cabin', 'medium', 'large'] as $size) {
            self::assertSelectorExists(sprintf('input[name="bags[%s]"][max="0"]', $size));
        }
    }
}
