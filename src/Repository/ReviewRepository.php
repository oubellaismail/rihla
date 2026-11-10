<?php

namespace App\Repository;

use App\Entity\Review;
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
}
