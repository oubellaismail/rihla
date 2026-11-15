<?php

namespace App\Repository;

use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Model\RatingSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /** Average rating (one decimal) and number of reviews received by a user, in one aggregate query. */
    public function summaryFor(User $target): RatingSummary
    {
        // A user not yet flushed cannot have received any review (and cannot be bound as a query parameter).
        if (null === $target->getId()) {
            return new RatingSummary(null, 0);
        }

        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS total', 'AVG(r.rating) AS average')
            ->where('r.target = :target')
            ->setParameter('target', $target)
            ->getQuery()
            ->getSingleResult();

        $count = (int) $row['total'];

        return new RatingSummary($count > 0 ? round((float) $row['average'], 1) : null, $count);
    }

    /**
     * Same as summaryFor() for several users at once, in one GROUP BY query (list pages: no query per row).
     *
     * @param iterable<User> $users
     *
     * @return array<int, RatingSummary> indexed by user id; users without review get RatingSummary(null, 0)
     */
    public function summariesFor(iterable $users): array
    {
        $summaries = [];
        foreach ($users as $user) {
            if (null !== $user->getId()) {
                $summaries[$user->getId()] = new RatingSummary(null, 0);
            }
        }
        if ([] === $summaries) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.target) AS target', 'COUNT(r.id) AS total', 'AVG(r.rating) AS average')
            ->where('r.target IN (:ids)')
            ->setParameter('ids', array_keys($summaries))
            ->groupBy('r.target')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $summaries[(int) $row['target']] = new RatingSummary(round((float) $row['average'], 1), (int) $row['total']);
        }

        return $summaries;
    }

    /**
     * Latest reviews that come with a written comment, written about the driver of the trip
     * (testimonials: passengers speaking of drivers, never drivers rating passengers).
     *
     * @return list<Review>
     */
    public function findLatestWithComment(int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.booking', 'b')
            ->join('b.trip', 't')
            ->where('r.target = t.driver')
            ->andWhere('r.comment IS NOT NULL')
            ->andWhere("r.comment <> ''")
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Review> reviews received by a user, newest first */
    public function receivedBy(User $target): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('a')
            ->join('r.author', 'a')
            ->where('r.target = :target')
            ->setParameter('target', $target)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Review> every review (both directions) written about the bookings of a trip */
    public function forTrip(Trip $trip): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.booking', 'b')
            ->where('b.trip = :trip')
            ->setParameter('trip', $trip)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
