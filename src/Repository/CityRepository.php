<?php

namespace App\Repository;

use App\Entity\City;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<City>
 */
class CityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, City::class);
    }

    /** Finds a city by name, ignoring the case ("essaouira" finds "Essaouira"). */
    public function findOneByNameInsensitive(string $name): ?City
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->where('LOWER(c.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<string> every city name, alphabetically */
    public function findAllNames(): array
    {
        return array_column(
            $this->createQueryBuilder('c')->select('c.name')->orderBy('c.name')->getQuery()->getScalarResult(),
            'name'
        );
    }
}
