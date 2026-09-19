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
}
