<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CheckResult;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
