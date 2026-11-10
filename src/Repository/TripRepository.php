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
}
