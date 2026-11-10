<?php

namespace App\Repository;

use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vehicle>
 */
class VehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /** @return list<int> ids of the owner's vehicles that are used by at least one trip */
    public function usedIdsOf(\App\Entity\User $owner): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(t.vehicle) AS id')
            ->from(\App\Entity\Trip::class, 't')
            ->join('t.vehicle', 'v')
            ->where('v.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['id'], $rows);
    }
}
