<?php

namespace App\Repository;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\TripStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    /** Number of trips of this driver that were cancelled. */
    public function countCancelledBy(User $driver): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.driver = :driver')
            ->andWhere('t.status = :status')
            ->setParameter('driver', $driver)
            ->setParameter('status', TripStatus::Cancelled)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Published trips that have not left yet, soonest first.
     *
     * @return list<Trip>
     */
    public function findUpcoming(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.stops', 's', 'WITH', 's.position = 0')
            ->where('t.status = :status')
            ->andWhere('s.departureAt > :now')
            ->setParameter('status', TripStatus::Published)
            ->setParameter('now', $now)
            ->orderBy('s.departureAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * All trips driven by a user, with their stops loaded.
     *
     * @return list<Trip>
     */
    public function findForDriver(User $driver): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->join('t.stops', 's')
            ->where('t.driver = :driver')
            ->setParameter('driver', $driver)
            ->getQuery()
            ->getResult();
    }

    /** Number of trips of this driver that were completed. */
    public function countCompletedBy(User $driver): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.driver = :driver')
            ->andWhere('t.status = :status')
            ->setParameter('driver', $driver)
            ->setParameter('status', TripStatus::Completed)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Trip> published trips of this driver that have not left yet, soonest first */
    public function upcomingPublishedBy(User $driver, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.stops', 's', 'WITH', 's.position = 0')
            ->where('t.driver = :driver')
            ->andWhere('t.status = :status')
            ->andWhere('s.departureAt > :now')
            ->setParameter('driver', $driver)
            ->setParameter('status', TripStatus::Published)
            ->setParameter('now', $now)
            ->orderBy('s.departureAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
