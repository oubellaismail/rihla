<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\DbTrips;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Base for browser-level tests: a client, the test database helpers and "now" frozen on
 * 2026-11-10 10:00, two days before the default trip (see DbTrips).
 */
abstract class WebFlowTestCase extends WebTestCase
{
    use ClockSensitiveTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected DbTrips $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep one EntityManager across requests, like the rollback transaction
        static::mockTime('2026-11-10 10:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = new DbTrips($this->em);
    }

    protected function login(User $user): void
    {
        $this->em->flush();
        $this->client->loginUser($user);
    }

    /**
     * Fresh copy of an entity from the database. The kernel resets the EntityManager after
     * each request, so objects created before a request are detached and cannot be refreshed.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected function reload(object $entity): object
    {
        return $this->em->find($entity::class, $entity->getId());
    }

    /** French flash messages and errors shown on the page. */
    protected function pageText(): string
    {
        return $this->client->getCrawler()->filter('body')->text();
    }
}
