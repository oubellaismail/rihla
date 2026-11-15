<?php

namespace App\Repository;

use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\TripStatus;
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

    /** @return list<Vehicle> the owner's cars that are not archived */
    public function activeOf(User $owner): array
    {
        return $this->findBy(['owner' => $owner, 'archivedAt' => null], ['id' => 'ASC']);
    }

    /**
     * How each of the owner's cars is used by trips.
     *
     * @return array<int, array{used: bool, nextDeparture: ?\DateTimeImmutable}> by vehicle id; a car no trip uses is absent.
     *                                                                           nextDeparture is the soonest departure of a published trip after $now.
     */
    public function usageOf(User $owner, \DateTimeImmutable $now): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(t.vehicle) AS id', 'MIN(CASE WHEN t.status = :published AND s.departureAt > :now THEN s.departureAt ELSE :none END) AS nextDeparture')
            ->from(Trip::class, 't')
            ->join('t.vehicle', 'v')
            ->leftJoin('t.stops', 's', 'WITH', 's.position = 0')
            ->where('v.owner = :owner')
            ->groupBy('t.vehicle')
            ->setParameter('owner', $owner)
            ->setParameter('published', TripStatus::Published)
            ->setParameter('now', $now)
            ->setParameter('none', null)
            ->getQuery()
            ->getScalarResult();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row['id']] = [
                'used' => true,
                'nextDeparture' => null !== $row['nextDeparture'] ? new \DateTimeImmutable($row['nextDeparture']) : null,
            ];
        }

        return $usage;
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
