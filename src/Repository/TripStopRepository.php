<?php

namespace App\Repository;

use App\Entity\City;
use App\Entity\TripStop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TripStop>
 */
class TripStopRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripStop::class);
    }

    /**
     * Places already used as meeting point of a stop in this city (placed on a map),
     * grouped by label, most used first.
     *
     * @return list<array{label: string, lat: float, lng: float, uses: int}>
     */
    public function popularMeetingPoints(City $city, int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.meetingPoint AS label, AVG(s.meetingLatitude) AS lat, AVG(s.meetingLongitude) AS lng, COUNT(s.id) AS uses')
            ->where('s.city = :city')
            ->andWhere('s.meetingPoint IS NOT NULL')
            ->andWhere("s.meetingPoint <> ''")
            ->andWhere('s.meetingLatitude IS NOT NULL')
            ->andWhere('s.meetingLongitude IS NOT NULL')
            ->groupBy('s.meetingPoint')
            ->orderBy('uses', 'DESC')
            ->addOrderBy('s.meetingPoint', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('city', $city)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'label' => (string) $r['label'],
            'lat' => round((float) $r['lat'], 6),
            'lng' => round((float) $r['lng'], 6),
            'uses' => (int) $r['uses'],
        ], $rows);
    }
}
