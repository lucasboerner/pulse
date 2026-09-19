<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Incident;
use App\Entity\Monitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Incident>
 */
class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    /**
     * The monitor's open incident — the one with no end — or null when the monitor
     * is currently healthy. At most one exists at a time: the engine opens a second
     * only after a success has closed the first. Ordering newest first and capping at
     * one row keeps the query honest even if that invariant is ever violated, and it
     * rides idx_incident_monitor_open on (monitor_id, ended_at).
     */
    public function findOpenFor(Monitor $monitor): ?Incident
    {
        return $this->createQueryBuilder('incident')
            ->andWhere('incident.monitor = :monitor')
            ->andWhere('incident.endedAt IS NULL')
            ->setParameter('monitor', $monitor)
            ->orderBy('incident.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * How many incidents opened for this monitor at or after $since — the detail
     * page's "incidents in the last 30 days" figure. Counts by startedAt so an
     * incident that is still ongoing is included the moment it opens. Rides
     * idx_incident_started.
     */
    public function countStartedSince(Monitor $monitor, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('incident')
            ->select('COUNT(incident.id)')
            ->andWhere('incident.monitor = :monitor')
            ->andWhere('incident.startedAt >= :since')
            ->setParameter('monitor', $monitor)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
