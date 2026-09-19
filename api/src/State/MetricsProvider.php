<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MetricsResource;
use App\Enum\CheckStatus;
use App\Repository\CheckResultRepository;
use App\Repository\CheckRollupRepository;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Rollup\HistoryAggregator;

/**
 * Builds the fleet-wide overview aggregate in one pass over a handful of portable
 * queries.
 *
 * The 24-hour response series and the per-monitor average latency read raw rows, which
 * sit inside the retention window. The 30-day uptime — fleet-wide and per monitor — and
 * each monitor's 45-day daily strip read hourly rollups (kept indefinitely) plus a
 * short raw tail for the hour not yet rolled up, so a 7-day retention delete never
 * collapses them. The wire shape is unchanged; only the source of those numbers moved.
 *
 * Everything is bucketed in PHP over scalar rows, because PERCENTILE_CONT and date_trunc
 * exist on PostgreSQL but not on the SQLite test schema. The rollups and the raw tail
 * fold through one shared HistoryAggregator so the two providers stay in step.
 *
 * @implements ProviderInterface<MetricsResource>
 */
final readonly class MetricsProvider implements ProviderInterface
{
    private const int SERIES_BUCKET_COUNT = 48;
    private const int SERIES_BUCKET_SECONDS = 1800;
    private const int DAILY_STRIP_DAYS = 45;
    private const int UPTIME_WINDOW_DAYS = 30;
    private const int RECENT_INCIDENT_LIMIT = 8;

    public function __construct(
        private MonitorRepository $monitors,
        private CheckResultRepository $checkResults,
        private CheckRollupRepository $rollups,
        private IncidentRepository $incidents,
        private HistoryAggregator $aggregator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MetricsResource
    {
        $now = new \DateTimeImmutable();
        $resource = new MetricsResource();
        $resource->id = 'current';

        $monitors = $this->monitors->findAll();
        $resource->monitorsTotal = \count($monitors);
        foreach ($monitors as $monitor) {
            if (!$monitor->isEnabled()) {
                ++$resource->monitorsPaused;

                continue;
            }

            $status = $monitor->getLastStatus();
            if (CheckStatus::Up === $status) {
                ++$resource->monitorsUp;
            } elseif (CheckStatus::Down === $status || CheckStatus::Degraded === $status) {
                ++$resource->needingAttention;
            }
        }

        // 24-hour window shared by the response series, the fleet average and the
        // per-monitor averages, so every response figure covers the same span.
        $seriesStart = $now->sub(new \DateInterval('PT'.(self::SERIES_BUCKET_COUNT * self::SERIES_BUCKET_SECONDS).'S'));
        $latencyRows = $this->checkResults->latencyRowsSince($seriesStart);

        $resource->avgResponseMs = $this->fleetAverageLatency($latencyRows);
        $resource->responseSeries = $this->buildResponseSeries($latencyRows, $seriesStart);

        $avgPerMonitor = [];
        foreach ($this->checkResults->avgLatencySince($seriesStart) as $row) {
            $avgPerMonitor[$row['monitorId']] = (int) round($row['avgLatencyMs']);
        }

        // Long-window reads: hourly rollups plus a raw tail for the hour not yet rolled
        // up. The rollups are fetched from the oldest window edge — the 45-day strip —
        // and the 30-day uptime filters within them; the raw tail begins after the newest
        // rolled-up bucket so the two never overlap. Bucket boundaries are aligned to
        // local midnight so a "day" is a calendar day. The counts are folded per monitor;
        // the fleet uptime sums those pairs across every monitor with data, soft-deleted
        // ones included, matching the raw counts this replaced.
        $dailyStart = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P'.(self::DAILY_STRIP_DAYS - 1).'D'));
        $since30d = $now->sub(new \DateInterval('P'.self::UPTIME_WINDOW_DAYS.'D'));
        $watermark = $this->rollups->newestBucketStart();
        $rawTailStart = null !== $watermark ? $watermark->add(new \DateInterval('PT1H')) : $dailyStart;

        $rollupByMonitor = $this->groupRollups($this->rollups->statusCountsSince($dailyStart));
        $rawByMonitor = $this->groupRawTail($this->checkResults->dailyStatusRowsSince($rawTailStart));

        $fleetUp = 0;
        $fleetTotal = 0;
        $uptimeByMonitor = [];
        foreach (array_keys($rollupByMonitor + $rawByMonitor) as $monitorId) {
            $counts = $this->aggregator->uptimeCounts(
                $rollupByMonitor[$monitorId] ?? [],
                $rawByMonitor[$monitorId] ?? [],
                $since30d,
            );
            $fleetUp += $counts['up'];
            $fleetTotal += $counts['total'];
            $uptimeByMonitor[$monitorId] = $counts['total'] > 0 ? $counts['up'] / $counts['total'] : null;
        }
        $resource->uptimeRatio30d = $fleetTotal > 0 ? $fleetUp / $fleetTotal : null;

        $rollups = [];
        foreach ($monitors as $monitor) {
            $monitorId = (string) $monitor->getId();

            $rollups[] = [
                'monitorId' => $monitorId,
                'name' => $monitor->getName(),
                'url' => $monitor->getUrl(),
                'enabled' => $monitor->isEnabled(),
                'lastStatus' => $monitor->getLastStatus()?->value,
                'avgLatencyMs' => $avgPerMonitor[$monitorId] ?? null,
                'uptimeRatio30d' => $uptimeByMonitor[$monitorId] ?? null,
                'dailyStatus' => $this->aggregator->dailyStrip(
                    $rollupByMonitor[$monitorId] ?? [],
                    $rawByMonitor[$monitorId] ?? [],
                    $dailyStart,
                    self::DAILY_STRIP_DAYS,
                ),
            ];
        }
        $resource->monitors = $rollups;

        $resource->openIncidents = $this->incidents->countOpen();
        $resource->recentIncidents = $this->recentIncidents();

        return $resource;
    }

    /**
     * Group the cross-monitor rollup rows by monitor into the per-monitor slices the
     * aggregator folds.
     *
     * @param list<array{monitorId: string, bucketStart: \DateTimeImmutable, upCount: int, degradedCount: int, downCount: int}> $rows
     *
     * @return array<string, list<array{bucketStart: \DateTimeImmutable, upCount: int, degradedCount: int, downCount: int}>>
     */
    private function groupRollups(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['monitorId']][] = [
                'bucketStart' => $row['bucketStart'],
                'upCount' => $row['upCount'],
                'degradedCount' => $row['degradedCount'],
                'downCount' => $row['downCount'],
            ];
        }

        return $grouped;
    }

    /**
     * Group the cross-monitor raw tail rows by monitor into the per-monitor slices the
     * aggregator folds.
     *
     * @param list<array{monitorId: string, checkedAt: \DateTimeImmutable, status: CheckStatus}> $rows
     *
     * @return array<string, list<array{checkedAt: \DateTimeImmutable, status: CheckStatus}>>
     */
    private function groupRawTail(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['monitorId']][] = [
                'checkedAt' => $row['checkedAt'],
                'status' => $row['status'],
            ];
        }

        return $grouped;
    }

    /**
     * The mean of every non-null latency in the window, rounded, or null when nothing
     * was timed.
     *
     * @param list<array{checkedAt: \DateTimeImmutable, latencyMs: int|null}> $rows
     */
    private function fleetAverageLatency(array $rows): ?int
    {
        $sum = 0;
        $count = 0;
        foreach ($rows as $row) {
            if (null !== $row['latencyMs']) {
                $sum += $row['latencyMs'];
                ++$count;
            }
        }

        return $count > 0 ? (int) round($sum / $count) : null;
    }

    /**
     * The 48 half-hour buckets, each carrying the median of its non-null latencies.
     *
     * @param list<array{checkedAt: \DateTimeImmutable, latencyMs: int|null}> $rows
     *
     * @return list<array{bucketStart: \DateTimeImmutable, medianLatencyMs: int|null}>
     */
    private function buildResponseSeries(array $rows, \DateTimeImmutable $windowStart): array
    {
        $windowStartTs = $windowStart->getTimestamp();
        /** @var list<list<int>> $buckets */
        $buckets = array_fill(0, self::SERIES_BUCKET_COUNT, []);

        foreach ($rows as $row) {
            if (null === $row['latencyMs']) {
                continue;
            }

            $index = intdiv($row['checkedAt']->getTimestamp() - $windowStartTs, self::SERIES_BUCKET_SECONDS);
            $index = max(0, min(self::SERIES_BUCKET_COUNT - 1, $index));
            $buckets[$index][] = $row['latencyMs'];
        }

        $series = [];
        for ($index = 0; $index < self::SERIES_BUCKET_COUNT; ++$index) {
            $latencies = $buckets[$index];
            sort($latencies);
            $series[] = [
                'bucketStart' => $windowStart->add(new \DateInterval('PT'.($index * self::SERIES_BUCKET_SECONDS).'S')),
                'medianLatencyMs' => $this->percentile($latencies, 50),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{monitorName: string, severity: string, startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable|null, cause: string|null}>
     */
    private function recentIncidents(): array
    {
        $incidents = [];
        foreach ($this->incidents->findRecent(self::RECENT_INCIDENT_LIMIT) as $incident) {
            $incidents[] = [
                'monitorName' => $incident->getMonitor()->getName(),
                'severity' => $incident->getSeverity()->value,
                'startedAt' => $incident->getStartedAt(),
                'endedAt' => $incident->getEndedAt(),
                'cause' => $incident->getCause(),
            ];
        }

        return $incidents;
    }

    /**
     * The value at the given percentile of a sorted integer array, nearest-rank —
     * integer in, integer out, identical on both database engines. Null for an empty
     * array.
     *
     * @param list<int> $sorted
     */
    private function percentile(array $sorted, int $percentile): ?int
    {
        $count = \count($sorted);
        if (0 === $count) {
            return null;
        }

        $rank = (int) ceil($percentile / 100 * $count);
        $index = max(0, min($count - 1, $rank - 1));

        return $sorted[$index];
    }
}
