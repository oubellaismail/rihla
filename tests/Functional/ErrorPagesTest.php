<?php

namespace App\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Error pages, rendered as in production through Symfony's preview route /_error/{code}.
 * Each page: a road milestone carrying the code, a French title, one line, one main action.
 * 404 and 403 use the site layout; 500 is standalone (no menu, no user, no database).
 */
final class ErrorPagesTest extends WebFlowTestCase
{
    private function error(int $code): Crawler
    {
        $crawler = $this->client->request('GET', '/_error/'.$code);
        self::assertResponseStatusCodeSame($code);

        return $crawler;
    }

    private function link(Crawler $crawler, string $href): Crawler
    {
        return $crawler->filter(sprintf('main a[href="%s"]', $href));
    }

    public function testNotFound(): void
    {
        $crawler = $this->error(404);

        self::assertSelectorTextContains('h1', 'Cette route ne mène nulle part');
        self::assertSelectorTextContains('title', 'Page introuvable');
        self::assertSelectorTextContains('.error-milestone', '404');
        self::assertStringContainsString("ce trajet n'est plus en ligne", $this->pageText());
        self::assertStringContainsString('Rechercher un trajet', $this->link($crawler, '/trips')->text());
        self::assertStringContainsString("Retour à l'accueil", $this->link($crawler, '/')->text());
        self::assertSelectorExists('header.site-header', 'the site menu stays, so the visitor can go on');
    }

    public function testForbidden(): void
    {
        $this->login($this->db->user('Karim'));

        $crawler = $this->error(403);

        self::assertSelectorTextContains('h1', 'Ce passage est réservé');
        self::assertSelectorTextContains('title', 'Accès refusé');
        self::assertSelectorTextContains('.error-milestone', '403');
        self::assertStringContainsString('Cette page appartient à un autre compte.', $this->pageText());
        self::assertStringContainsString("Retour à l'accueil", $this->link($crawler, '/')->text());
        self::assertStringContainsString('Changer de compte', $this->link($crawler, '/logout')->text());
        self::assertSelectorExists('header.site-header');
    }

    public function testServerErrorIsStandalone(): void
    {
        $this->login($this->db->user('Karim'));

        $crawler = $this->error(500);

        self::assertSelectorTextContains('h1', 'Petit arrêt sur le bas-côté');
        self::assertSelectorTextContains('title', 'Erreur');
        self::assertSelectorTextContains('.error-milestone', '500');
        self::assertStringContainsString("Une erreur s'est produite de notre côté. Réessayez dans un instant.", $this->pageText());
        self::assertStringContainsString('Réessayer', $crawler->filter('main')->text());
        self::assertStringContainsString("Retour à l'accueil", $this->link($crawler, '/')->text());
        self::assertSelectorNotExists('header.site-header', 'no menu: it reads the user and could fail too');
        self::assertStringNotContainsString('Karim', $this->pageText(), 'no user data on the standalone page');
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
    }

    /** @return iterable<string, array{int}> */
    public static function otherCodes(): iterable
    {
        yield 'method not allowed' => [405];
        yield 'too many requests' => [429];
        yield 'service unavailable' => [503];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('otherCodes')]
    public function testOtherCodesGetTheGenericPage(int $code): void
    {
        $crawler = $this->error($code);

        self::assertSelectorTextContains('h1', "Quelque chose n'a pas fonctionné");
        self::assertSelectorTextContains('.error-milestone', (string) $code);
        self::assertStringContainsString("Retour à l'accueil", $this->link($crawler, '/')->text());
    }

    public function testNoPageShowsSymfonysDefaultText(): void
    {
        foreach ([403, 404, 500, 429] as $code) {
            $this->client->request('GET', '/_error/'.$code);
            self::assertStringNotContainsString('Oops', (string) $this->client->getResponse()->getContent(), (string) $code);
        }
    }
}
