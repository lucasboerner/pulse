<?php

declare(strict_types=1);

namespace App\Rollup;

use App\Entity\CheckRollup;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use App\Repository\CheckResultRepository;
use App\Repository\CheckRollupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Folds elapsed hours of raw check results into check_rollup and then, and only then,
 * deletes the raw rows behind the retention window.
 *
 * The order is the whole point (see the schema specification's retention path):
 * aggregate a bucket, upsert it, commit, and only after that delete raw rows — so no
 * raw row is ever deleted before it was rolled up, and a crash anywhere loses nothing
 * because the next run redoes the bucket. Each bucket [H, H+1) becomes one rollup per
 * monitor and region; the walk starts after the newest bucket already stored and stops
 * before the hour still in progress.
 */
final readonly class CheckRollupJob
{
    // Bound the catch-up walk: a machine whose rollup job was idle for a long time works
    // through its backlog over several runs rather than in one unbounded transaction.
    private const int MAX_BUCKETS_PER_RUN = 200;

    // Bound the retention delete: at most this many passes of this many rows per run, so
    // one invocation cannot run for minutes on a large backlog.
    private const int RETENTION_DELETE_BATCH = 5000;
    private const int RETENTION_MAX_PASSES = 20;

    private const int PERCENTILE = 95;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CheckResultRepository $checkResults,
        private CheckRollupRepository $rollups,
        private HistoryAggregator $aggregator,
        private LoggerInterface $logger,
        private int $rawRetentionDays,
    ) {
    }

    /**
     * Aggregate every elapsed, not-yet-rolled-up bucket, then — unless asked not to —
     * delete the raw rows behind the retention window.
     */
    public function run(bool $withRetention = true): RollupSummary
    {
        $rollupsWritten = 0;
        $rowsAggregated = 0;

        foreach ($this->pendingBuckets() as $bucketStart) {
            ['rollups' => $rollups, 'rows' => $rows] = $this->aggregateBucket($bucketStart);
            $rollupsWritten += $rollups;
            $rowsAggregated += $rows;
        }

        if ($rollupsWritten > 0) {
            $this->logger->info('Rolled up elapsed check-result hours.', [
                'rollupsWritten' => $rollupsWritten,
                'rowsAggregated' => $rowsAggregated,
            ]);
        }

        $retention = $withRetention ? $this->deleteExpiredRaw() : new RetentionSummary(0, null);

        return new RollupSummary($rollupsWritten, $rowsAggregated, $retention->rowsDeleted, $retention->cutoff);
    }

    /**
     * The retention delete, its own step in its own transaction, run only after the
     * aggregation has committed. Public so it can be exercised on its own — the guard in
     * retentionCutoff() is what keeps it from destroying history that is not yet rolled
     * up.
     */
    public function deleteExpiredRaw(): RetentionSummary
    {
        $cutoff = $this->retentionCutoff();
        if (null === $cutoff) {
            return new RetentionSummary(0, null);
        }

        $rowsDeleted = 0;
        for ($pass = 0; $pass < self::RETENTION_MAX_PASSES; ++$pass) {
            $ids = $this->checkResults->idsBefore($cutoff, self::RETENTION_DELETE_BATCH);
            if ([] === $ids) {
                break;
            }

            $deleted = 0;
            $this->entityManager->wrapInTransaction(function () use ($ids, &$deleted): void {
                $deleted = $this->checkResults->deleteByIds($ids);
            });
            $rowsDeleted += $deleted;

            if (\count($ids) < self::RETENTION_DELETE_BATCH) {
                break;
            }
        }

        if ($rowsDeleted > 0) {
            $this->logger->info('Deleted raw check results behind the retention window.', [
                'rowsDeleted' => $rowsDeleted,
                'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
            ]);
        }

        return new RetentionSummary($rowsDeleted, $cutoff);
    }

    /**
     * The elapsed buckets to process this run, oldest first: from the hour after the
     * newest bucket already stored, or — when nothing is stored — from the hour holding
     * the oldest surviving raw row, up to but not including the current hour, capped.
     *
     * @return list<\DateTimeImmutable>
     */
    private function pendingBuckets(): array
    {
        $currentHourStart = $this->floorToHour(new \DateTimeImmutable());

        $newest = $this->rollups->newestBucketStart();
        if (null !== $newest) {
            $start = $this->floorToHour($newest)->add(new \DateInterval('PT1H'));
        } else {
            $oldestRaw = $this->checkResults->oldestCheckedAt();
            if (null === $oldestRaw) {
                return [];
            }
            $start = $this->floorToHour($oldestRaw);
        }

        $buckets = [];
        for (
            $bucket = $start;
            $bucket < $currentHourStart && \count($buckets) < self::MAX_BUCKETS_PER_RUN;
            $bucket = $bucket->add(new \DateInterval('PT1H'))
        ) {
            $buckets[] = $bucket;
        }

        return $buckets;
    }

    /**
     * Fold one bucket [start, start + 1h) into check_rollup and return how many rollup
     * rows it wrote (one per monitor and region) and how many raw checks those rows
     * aggregated. A bucket with no check writes nothing (absence means "no data", the
     * same as a null day in a strip).
     *
     * @return array{rollups: int, rows: int}
     */
    private function aggregateBucket(\DateTimeImmutable $start): array
    {
        $end = $start->add(new \DateInterval('PT1H'));

        $statusCounts = $this->checkResults->statusCountsForBucket($start, $end);
        if ([] === $statusCounts) {
            return ['rollups' => 0, 'rows' => 0];
        }

        // Fold the three portable scans into one aggregate per (monitor, region). Counts,
        // min, max and average come from SQL; the p95 is computed in PHP.
        /** @var array<string, array{monitorId: string, region: string, upCount: int, degradedCount: int, downCount: int, latencyMinMs: int|null, latencyAvgMs: int|null, latencyMaxMs: int|null, latencyP95Ms: int|null}> $aggregates */
        $aggregates = [];
        foreach ($statusCounts as $row) {
            $key = $this->key($row['monitorId'], $row['region']);
            $aggregates[$key] ??= [
                'monitorId' => $row['monitorId'],
                'region' => $row['region'],
                'upCount' => 0,
                'degradedCount' => 0,
                'downCount' => 0,
                'latencyMinMs' => null,
                'latencyAvgMs' => null,
                'latencyMaxMs' => null,
                'latencyP95Ms' => null,
            ];

            match ($row['status']) {
                CheckStatus::Up->value => $aggregates[$key]['upCount'] += $row['checkCount'],
                CheckStatus::Degraded->value => $aggregates[$key]['degradedCount'] += $row['checkCount'],
                CheckStatus::Down->value => $aggregates[$key]['downCount'] += $row['checkCount'],
                default => null,
            };
        }

        foreach ($this->checkResults->latencyStatsForBucket($start, $end) as $row) {
            $key = $this->key($row['monitorId'], $row['region']);
            if (!isset($aggregates[$key])) {
                continue;
            }

            $aggregates[$key]['latencyMinMs'] = $row['latencyMinMs'];
            $aggregates[$key]['latencyMaxMs'] = $row['latencyMaxMs'];
            $aggregates[$key]['latencyAvgMs'] = (int) round($row['latencyAvgMs']);
        }

        /** @var array<string, list<int>> $samples */
        $samples = [];
        foreach ($this->checkResults->latencySamplesForBucket($start, $end) as $row) {
            $samples[$this->key($row['monitorId'], $row['region'])][] = $row['latencyMs'];
        }
        foreach ($samples as $key => $latencies) {
            if (!isset($aggregates[$key])) {
                continue;
            }

            // Already ascending from SQL; p95 by the shared nearest-rank definition.
            $aggregates[$key]['latencyP95Ms'] = $this->aggregator->percentile($latencies, self::PERCENTILE);
        }

        // Trap 1: SQLite has no ON CONFLICT, so load the bucket's existing rollups and
        // decide update-versus-insert in PHP; the unique constraint is only the backstop.
        // The monitor is a proxy, so reading its id to key the map costs no query.
        $existing = [];
        foreach ($this->rollups->forBucket($start) as $rollup) {
            $existing[$this->key((string) $rollup->getMonitor()->getId(), $rollup->getRegion())] = $rollup;
        }

        $rowsAggregated = 0;
        $this->entityManager->wrapInTransaction(function () use ($aggregates, $existing, $start, &$rowsAggregated): void {
            foreach ($aggregates as $key => $aggregate) {
                $rollup = $existing[$key] ?? null;
                if (null === $rollup) {
                    // Trap 4: attach the owner by reference — a lazy proxy, not a query —
                    // so a soft-deleted monitor's rows still roll up instead of vanishing.
                    $monitor = $this->entityManager->getReference(Monitor::class, Uuid::fromString($aggregate['monitorId']));
                    \assert($monitor instanceof Monitor);

                    $rollup = new CheckRollup();
                    $rollup->setMonitor($monitor);
                    $rollup->setBucketStart($start);
                    $rollup->setRegion($aggregate['region']);
                    $this->entityManager->persist($rollup);
                }

                $rollup
                    ->setUpCount($aggregate['upCount'])
                    ->setDegradedCount($aggregate['degradedCount'])
                    ->setDownCount($aggregate['downCount'])
                    ->setLatencyMinMs($aggregate['latencyMinMs'])
                    ->setLatencyAvgMs($aggregate['latencyAvgMs'])
                    ->setLatencyMaxMs($aggregate['latencyMaxMs'])
                    ->setLatencyP95Ms($aggregate['latencyP95Ms']);

                $rowsAggregated += $aggregate['upCount'] + $aggregate['degradedCount'] + $aggregate['downCount'];
            }

            $this->entityManager->flush();
        });

        return ['rollups' => \count($aggregates), 'rows' => $rowsAggregated];
    }

    /**
     * The retention cutoff: the earlier of the plain window (now minus the retention
     * days) and the start of the oldest bucket not yet rolled up. Deleting only behind
     * that guard means a job that has been failing never destroys the sole surviving copy
     * of unrolled history. Null when there is nothing to delete against.
     */
    private function retentionCutoff(): ?\DateTimeImmutable
    {
        $windowCutoff = (new \DateTimeImmutable())->sub(new \DateInterval('P'.$this->rawRetentionDays.'D'));

        $newest = $this->rollups->newestBucketStart();
        if (null !== $newest) {
            $unrolledFrom = $this->floorToHour($newest)->add(new \DateInterval('PT1H'));
        } else {
            $oldestRaw = $this->checkResults->oldestCheckedAt();
            if (null === $oldestRaw) {
                return null;
            }
            $unrolledFrom = $this->floorToHour($oldestRaw);
        }

        return $windowCutoff < $unrolledFrom ? $windowCutoff : $unrolledFrom;
    }

    private function floorToHour(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $utc = $moment->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), 0, 0);
    }

    private function key(string $monitorId, string $region): string
    {
        return $monitorId.'|'.$region;
    }
}
