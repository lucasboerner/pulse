<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Monitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Monitor>
 */
class MonitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Monitor::class);
    }

    /**
     * Enabled monitors due at or before $now, soonest first, capped so one tick
     * can never dispatch an unbounded batch. The global Gedmo soft-delete filter
     * already excludes deleted rows, so there is no deleted_at condition here.
     *
     * @return list<Monitor>
     */
    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('monitor')
            ->andWhere('monitor.enabled = :enabled')
            ->andWhere('monitor.nextCheckAt <= :now')
            ->setParameter('enabled', true)
            ->setParameter('now', $now)
            ->orderBy('monitor.nextCheckAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
