<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CheckRollup;
use App\Entity\Monitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CheckRollup>
 */
class CheckRollupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckRollup::class);
    }

    /**
     * The newest bucketStart already in the table, or null when it is empty. The
     * watermark the rollup job and the long-window readers share: the job aggregates
     * the buckets after it, the readers stop trusting rollups past it and switch to raw
     * rows.
     */
    public function newestBucketStart(): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('rollup')
            ->select('MAX(rollup.bucketStart)')
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $value) {
            return null;
        }

        // MAX() comes back as a scalar string, not through the field's type, so it is
        // parsed here. The stored value is UTC (the app runs in UTC), so no offset in
        // the string is unambiguous.
        return new \DateTimeImmutable((string) $value);
    }

    /**
     * Every rollup for one bucket, all regions, as entities — the existing rows the
     * hourly upsert loads to decide update-versus-insert. The monitor is a proxy, so
     * reading its identifier to key the map costs no query and never trips the
     * soft-delete filter.
     *
     * @return list<CheckRollup>
     */
    public function forBucket(\DateTimeImmutable $bucketStart): array
    {
        return $this->createQueryBuilder('rollup')
            ->andWhere('rollup.bucketStart = :bucketStart')
            ->setParameter('bucketStart', $bucketStart)
            ->getQuery()
            ->getResult();
    }

    /**
     * Per-monitor, per-bucket counts at or after $since, for every monitor at once — one
     * cross-monitor scan that feeds both the overview's per-monitor strips and its
     * uptime ratios. Scalar rows, normalised types, bucketing by day left to PHP, just
     * like the raw repository. Not joined to monitor, so a soft-deleted monitor's
     * rollups are included, matching the raw counts.
     *
     * @return list<array{monitorId: string, bucketStart: \DateTimeImmutable, upCount: int, degradedCount: int, downCount: int}>
     */
    public function statusCountsSince(\DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('rollup')
            ->select(
                'IDENTITY(rollup.monitor) AS monitorId',
                'rollup.bucketStart AS bucketStart',
                'rollup.upCount AS upCount',
                'rollup.degradedCount AS degradedCount',
                'rollup.downCount AS downCount',
            )
            ->andWhere('rollup.bucketStart >= :since')
            ->setParameter('since', $since)
            ->orderBy('rollup.bucketStart', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $bucketStart = $row['bucketStart'];

            return [
                'monitorId' => (string) $row['monitorId'],
                'bucketStart' => $bucketStart instanceof \DateTimeImmutable ? $bucketStart : new \DateTimeImmutable((string) $bucketStart),
                'upCount' => (int) $row['upCount'],
                'degradedCount' => (int) $row['degradedCount'],
                'downCount' => (int) $row['downCount'],
            ];
        }, $rows);
    }

    /**
     * One monitor's per-bucket counts at or after $since, oldest first — the
     * single-monitor twin of statusCountsSince, feeding the detail page's 7/30-day
     * uptime and its 90-day strip from one scan.
     *
     * @return list<array{bucketStart: \DateTimeImmutable, upCount: int, degradedCount: int, downCount: int}>
     */
    public function statusCountsForMonitorSince(Monitor $monitor, \DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('rollup')
            ->select(
                'rollup.bucketStart AS bucketStart',
                'rollup.upCount AS upCount',
                'rollup.degradedCount AS degradedCount',
                'rollup.downCount AS downCount',
            )
            ->andWhere('rollup.monitor = :monitor')
            ->andWhere('rollup.bucketStart >= :since')
            ->setParameter('monitor', $monitor)
            ->setParameter('since', $since)
            ->orderBy('rollup.bucketStart', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $bucketStart = $row['bucketStart'];

            return [
                'bucketStart' => $bucketStart instanceof \DateTimeImmutable ? $bucketStart : new \DateTimeImmutable((string) $bucketStart),
                'upCount' => (int) $row['upCount'],
                'degradedCount' => (int) $row['degradedCount'],
                'downCount' => (int) $row['downCount'],
            ];
        }, $rows);
    }
}
