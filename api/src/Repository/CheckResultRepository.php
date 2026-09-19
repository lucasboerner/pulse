<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CheckResult;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CheckResult>
 */
class CheckResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckResult::class);
    }

    /**
     * The scalar rows for a monitor's window — checked time, status and latency,
     * nothing else — for the detail page's 24-hour aggregation. At the 15-second
     * minimum interval a day holds 5760 rows, so this never hydrates entities: it
     * selects three columns and the aggregation (counts, median, p95, buckets)
     * happens in PHP, which also keeps it portable across PostgreSQL and the SQLite
     * test schema. Rides idx_check_result_monitor_time on (monitor_id, checked_at).
     *
     * @return list<array{checkedAt: \DateTimeImmutable, status: CheckStatus, latencyMs: int|null}>
     */
    public function windowFor(Monitor $monitor, \DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select('result.checkedAt AS checkedAt', 'result.status AS status', 'result.latencyMs AS latencyMs')
            ->andWhere('result.monitor = :monitor')
            ->andWhere('result.checkedAt >= :since')
            ->setParameter('monitor', $monitor)
            ->setParameter('since', $since)
            ->orderBy('result.checkedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Normalise the scalar rows to a fixed shape so the provider's arithmetic
        // does not depend on how array hydration types an enum or a datetime column.
        return array_map(static function (array $row): array {
            $checkedAt = $row['checkedAt'];
            $status = $row['status'];

            return [
                'checkedAt' => $checkedAt instanceof \DateTimeImmutable ? $checkedAt : new \DateTimeImmutable((string) $checkedAt),
                'status' => $status instanceof CheckStatus ? $status : CheckStatus::from((string) $status),
                'latencyMs' => null === $row['latencyMs'] ? null : (int) $row['latencyMs'],
            ];
        }, $rows);
    }

    /**
     * The newest raw results for a monitor, entities this time — the recent-checks
     * table needs every column and the row count is small. Newest first, capped at
     * $limit, on the same monitor+time index.
     *
     * @return list<CheckResult>
     */
    public function recentFor(Monitor $monitor, int $limit): array
    {
        return $this->createQueryBuilder('result')
            ->andWhere('result.monitor = :monitor')
            ->setParameter('monitor', $monitor)
            ->orderBy('result.checkedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Per-monitor, per-status check tallies since $since, for every monitor at once —
     * one GROUP BY COUNT the overview folds into per-monitor {up, total} ratios and a
     * fleet-wide aggregate. COUNT and GROUP BY are portable across PostgreSQL and the
     * SQLite test schema, so this stays SQL; only the folding happens in PHP. The
     * status arrives as its scalar value, normalised to the enum's string form.
     *
     * @return list<array{monitorId: string, status: string, checkCount: int}>
     */
    public function uptimeCountsSince(\DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select('IDENTITY(result.monitor) AS monitorId', 'result.status AS status', 'COUNT(result.id) AS checkCount')
            ->andWhere('result.checkedAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('result.monitor')
            ->addGroupBy('result.status')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $status = $row['status'];

            return [
                'monitorId' => (string) $row['monitorId'],
                'status' => $status instanceof CheckStatus ? $status->value : (string) $status,
                'checkCount' => (int) $row['checkCount'],
            ];
        }, $rows);
    }

    /**
     * Per-monitor average latency since $since, over the non-null latencies only, for
     * every monitor at once. AVG and GROUP BY are portable; a Down check (null
     * latency) is excluded so it never drags the mean toward zero. A monitor with no
     * timed check in the window is simply absent from the result.
     *
     * @return list<array{monitorId: string, avgLatencyMs: float}>
     */
    public function avgLatencySince(\DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select('IDENTITY(result.monitor) AS monitorId', 'AVG(result.latencyMs) AS avgLatencyMs')
            ->andWhere('result.checkedAt >= :since')
            ->andWhere('result.latencyMs IS NOT NULL')
            ->setParameter('since', $since)
            ->groupBy('result.monitor')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'monitorId' => (string) $row['monitorId'],
            'avgLatencyMs' => (float) $row['avgLatencyMs'],
        ], $rows);
    }

    /**
     * Cross-monitor latency samples since $since — checked time and latency only,
     * oldest first — for the overview's fleet-wide response series. Rides
     * idx_check_result_checked_at. The bucketing and the median per bucket run in PHP
     * over these scalar rows, keeping PERCENTILE_CONT out of the query. A Down check
     * carries a null latency and is skipped by the caller.
     *
     * @return list<array{checkedAt: \DateTimeImmutable, latencyMs: int|null}>
     */
    public function latencyRowsSince(\DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select('result.checkedAt AS checkedAt', 'result.latencyMs AS latencyMs')
            ->andWhere('result.checkedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('result.checkedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $checkedAt = $row['checkedAt'];

            return [
                'checkedAt' => $checkedAt instanceof \DateTimeImmutable ? $checkedAt : new \DateTimeImmutable((string) $checkedAt),
                'latencyMs' => null === $row['latencyMs'] ? null : (int) $row['latencyMs'],
            ];
        }, $rows);
    }

    /**
     * Cross-monitor status samples since $since — owning monitor, checked time and
     * status, oldest first — for the overview's per-monitor daily strips. One scan
     * feeds every monitor's strip: the caller buckets by monitor and day in PHP and
     * keeps the worst status per day. Rides idx_check_result_checked_at.
     *
     * @return list<array{monitorId: string, checkedAt: \DateTimeImmutable, status: CheckStatus}>
     */
    public function dailyStatusRowsSince(\DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select('IDENTITY(result.monitor) AS monitorId', 'result.checkedAt AS checkedAt', 'result.status AS status')
            ->andWhere('result.checkedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('result.checkedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $checkedAt = $row['checkedAt'];
            $status = $row['status'];

            return [
                'monitorId' => (string) $row['monitorId'],
                'checkedAt' => $checkedAt instanceof \DateTimeImmutable ? $checkedAt : new \DateTimeImmutable((string) $checkedAt),
                'status' => $status instanceof CheckStatus ? $status : CheckStatus::from((string) $status),
            ];
        }, $rows);
    }

    /**
     * One monitor's status samples since $since — checked time and status, oldest
     * first — for the detail page's 90-day daily strip. The single-monitor twin of
     * dailyStatusRowsSince, riding idx_check_result_monitor_time; the caller buckets
     * into days and keeps the worst status per day.
     *
     * @return list<array{checkedAt: \DateTimeImmutable, status: CheckStatus}>
     */
    public function statusRowsForSince(Monitor $monitor, \DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select('result.checkedAt AS checkedAt', 'result.status AS status')
            ->andWhere('result.monitor = :monitor')
            ->andWhere('result.checkedAt >= :since')
            ->setParameter('monitor', $monitor)
            ->setParameter('since', $since)
            ->orderBy('result.checkedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $checkedAt = $row['checkedAt'];
            $status = $row['status'];

            return [
                'checkedAt' => $checkedAt instanceof \DateTimeImmutable ? $checkedAt : new \DateTimeImmutable((string) $checkedAt),
                'status' => $status instanceof CheckStatus ? $status : CheckStatus::from((string) $status),
            ];
        }, $rows);
    }

    /**
     * The checkedAt of the oldest surviving raw row, or null when the table is empty.
     * The rollup job starts its backfill at the hour containing this row when no rollup
     * exists yet, and retention never deletes behind it while it is still unrolled.
     */
    public function oldestCheckedAt(): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('result')
            ->select('MIN(result.checkedAt)')
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $value) {
            return null;
        }

        // MIN() comes back as a scalar string, not through the field's type. The stored
        // value is UTC (the app runs in UTC), so parsing it without an offset is safe.
        return new \DateTimeImmutable((string) $value);
    }

    /**
     * Per-monitor, per-region, per-status check tallies for one bucket [start, end).
     * COUNT and GROUP BY are portable, so the counts happen in SQL; the caller folds the
     * status rows into up/degraded/down and grouping stays keyed on the region the raw
     * row carried, so the rollup's unique key is honoured. Not joined to monitor, so a
     * soft-deleted monitor's rows are still counted.
     *
     * @return list<array{monitorId: string, region: string, status: string, checkCount: int}>
     */
    public function statusCountsForBucket(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select(
                'IDENTITY(result.monitor) AS monitorId',
                'result.region AS region',
                'result.status AS status',
                'COUNT(result.id) AS checkCount',
            )
            ->andWhere('result.checkedAt >= :start')
            ->andWhere('result.checkedAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('result.monitor')
            ->addGroupBy('result.region')
            ->addGroupBy('result.status')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $status = $row['status'];

            return [
                'monitorId' => (string) $row['monitorId'],
                'region' => (string) $row['region'],
                'status' => $status instanceof CheckStatus ? $status->value : (string) $status,
                'checkCount' => (int) $row['checkCount'],
            ];
        }, $rows);
    }

    /**
     * Per-monitor, per-region min, max and average latency for one bucket [start, end),
     * over the non-null latencies only. MIN, MAX and AVG are portable, so these belong
     * in SQL; the p95 does not and is computed in PHP from latencySamplesForBucket. A
     * monitor whose every check in the bucket timed out is simply absent here.
     *
     * @return list<array{monitorId: string, region: string, latencyMinMs: int, latencyMaxMs: int, latencyAvgMs: float}>
     */
    public function latencyStatsForBucket(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select(
                'IDENTITY(result.monitor) AS monitorId',
                'result.region AS region',
                'MIN(result.latencyMs) AS latencyMinMs',
                'MAX(result.latencyMs) AS latencyMaxMs',
                'AVG(result.latencyMs) AS latencyAvgMs',
            )
            ->andWhere('result.checkedAt >= :start')
            ->andWhere('result.checkedAt < :end')
            ->andWhere('result.latencyMs IS NOT NULL')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('result.monitor')
            ->addGroupBy('result.region')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'monitorId' => (string) $row['monitorId'],
            'region' => (string) $row['region'],
            'latencyMinMs' => (int) $row['latencyMinMs'],
            'latencyMaxMs' => (int) $row['latencyMaxMs'],
            'latencyAvgMs' => (float) $row['latencyAvgMs'],
        ], $rows);
    }

    /**
     * The non-null latency samples for one bucket [start, end), monitor and region
     * ordered, each ascending — the raw material for the p95, computed in PHP because
     * the nearest-rank percentile is not portable SQL. The caller groups by
     * monitor+region; within a group the samples are already sorted.
     *
     * @return list<array{monitorId: string, region: string, latencyMs: int}>
     */
    public function latencySamplesForBucket(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select(
                'IDENTITY(result.monitor) AS monitorId',
                'result.region AS region',
                'result.latencyMs AS latencyMs',
            )
            ->andWhere('result.checkedAt >= :start')
            ->andWhere('result.checkedAt < :end')
            ->andWhere('result.latencyMs IS NOT NULL')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('result.monitor', 'ASC')
            ->addOrderBy('result.region', 'ASC')
            ->addOrderBy('result.latencyMs', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'monitorId' => (string) $row['monitorId'],
            'region' => (string) $row['region'],
            'latencyMs' => (int) $row['latencyMs'],
        ], $rows);
    }

    /**
     * Up to $limit ids of raw rows whose checkedAt is before $cutoff, oldest first — one
     * page of the retention delete. Rides idx_check_result_checked_at.
     *
     * @return list<string>
     */
    public function idsBefore(\DateTimeImmutable $cutoff, int $limit): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('result')
            ->select('result.id AS id')
            ->andWhere('result.checkedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('result.checkedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * Hard-delete the raw rows with the given ids and return how many were removed. DQL
     * DELETE carries no LIMIT, so the retention step pages the ids in and deletes them
     * by id; an empty page deletes nothing.
     *
     * @param list<string> $ids
     */
    public function deleteByIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        /** @var int $affected */
        $affected = $this->createQueryBuilder('result')
            ->delete()
            ->andWhere('result.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getQuery()
            ->execute();

        return $affected;
    }
}
