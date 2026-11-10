<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /** The most recent booking that is still confirmed, if any. */
    public function findLatestConfirmed(): ?Booking
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', BookingStatus::Confirmed)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * All bookings made by a passenger, with their trip, stops and payment loaded.
     *
     * @return list<Booking>
     */
    public function findForPassenger(User $passenger): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('t', 'fs', 'ts', 'd')
            ->join('b.trip', 't')
            ->join('b.fromStop', 'fs')
            ->join('b.toStop', 'ts')
            ->join('t.driver', 'd')
            ->where('b.passenger = :passenger')
            ->setParameter('passenger', $passenger)
            ->orderBy('fs.departureAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
