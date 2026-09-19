<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MetricsResource;
use App\Enum\CheckStatus;
use App\Repository\CheckResultRepository;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;

/**
 * Builds the fleet-wide overview aggregate in one pass over a handful of portable
 * queries.
 *
 * The cheap fleet numbers come from GROUP BY COUNT / AVG queries; the response series
 * and the per-monitor daily strips are bucketed in PHP over scalar rows, because
 * PERCENTILE_CONT and date_trunc exist on PostgreSQL but not on the SQLite test schema.
 * The daily strips for every monitor come from a single dailyStatusRowsSince scan, not
 * one query per monitor, and the median per response bucket uses the same nearest-rank
 * percentile() the detail provider uses.
 *
 * @implements ProviderInterface<MetricsResource>
 */
final readonly class MetricsProvider implements ProviderInterface
{
    private const int SERIES_BUCKET_COUNT = 48;
    private const int SERIES_BUCKET_SECONDS = 1800;
    private const int DAILY_STRIP_DAYS = 45;
    private const int DAY_SECONDS = 86400;
    private const int UPTIME_WINDOW_DAYS = 30;
    private const int RECENT_INCIDENT_LIMIT = 8;

    public function __construct(
        private MonitorRepository $monitors,
        private CheckResultRepository $checkResults,
        private IncidentRepository $incidents,
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

        // Fleet uptime and per-monitor uptime from one 30-day GROUP BY COUNT.
        $since30d = $now->sub(new \DateInterval('P'.self::UPTIME_WINDOW_DAYS.'D'));
        [$uptimePerMonitor, $fleetUp, $fleetTotal] = $this->foldUptimeCounts(
            $this->checkResults->uptimeCountsSince($since30d),
        );
        $resource->uptimeRatio30d = $fleetTotal > 0 ? $fleetUp / $fleetTotal : null;

        // 24-hour window shared by the response series, the fleet average and the
        // per-monitor averages, so every response figure covers the same span.
        $seriesEnd = $now;
        $seriesStart = $now->sub(new \DateInterval('PT'.(self::SERIES_BUCKET_COUNT * self::SERIES_BUCKET_SECONDS).'S'));
        $latencyRows = $this->checkResults->latencyRowsSince($seriesStart);

        $resource->avgResponseMs = $this->fleetAverageLatency($latencyRows);
        $resource->responseSeries = $this->buildResponseSeries($latencyRows, $seriesStart);

        $avgPerMonitor = [];
        foreach ($this->checkResults->avgLatencySince($seriesStart) as $row) {
            $avgPerMonitor[$row['monitorId']] = (int) round($row['avgLatencyMs']);
        }

        // One scan feeds every monitor's daily strip. Bucket boundaries are aligned to
        // local midnight so a "day" is a calendar day, not a rolling 24-hour window.
        $dailyStart = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P'.(self::DAILY_STRIP_DAYS - 1).'D'));
        $dailyLabels = $this->dayLabels($dailyStart, self::DAILY_STRIP_DAYS);
        $dailyWorst = $this->foldDailyStatus(
            $this->checkResults->dailyStatusRowsSince($dailyStart),
            $dailyStart,
            self::DAILY_STRIP_DAYS,
        );

        $rollups = [];
        foreach ($monitors as $monitor) {
            $monitorId = (string) $monitor->getId();
            $counts = $uptimePerMonitor[$monitorId] ?? ['up' => 0, 'total' => 0];

            $rollups[] = [
                'monitorId' => $monitorId,
                'name' => $monitor->getName(),
                'url' => $monitor->getUrl(),
                'enabled' => $monitor->isEnabled(),
                'lastStatus' => $monitor->getLastStatus()?->value,
                'avgLatencyMs' => $avgPerMonitor[$monitorId] ?? null,
                'uptimeRatio30d' => $counts['total'] > 0 ? $counts['up'] / $counts['total'] : null,
                'dailyStatus' => $this->dailyStrip($dailyLabels, $dailyWorst[$monitorId] ?? []),
            ];
        }
        $resource->monitors = $rollups;

        $resource->openIncidents = $this->incidents->countOpen();
        $resource->recentIncidents = $this->recentIncidents();

        return $resource;
    }

    /**
     * Fold the per-monitor, per-status counts into a per-monitor {up, total} map and
     * the fleet-wide up and total tallies.
     *
     * @param list<array{monitorId: string, status: string, checkCount: int}> $rows
     *
     * @return array{0: array<string, array{up: int, total: int}>, 1: int, 2: int}
     */
    private function foldUptimeCounts(array $rows): array
    {
        $perMonitor = [];
        $fleetUp = 0;
        $fleetTotal = 0;

        foreach ($rows as $row) {
            $monitorId = $row['monitorId'];
            $count = $row['checkCount'];
            $perMonitor[$monitorId] ??= ['up' => 0, 'total' => 0];
            $perMonitor[$monitorId]['total'] += $count;
            $fleetTotal += $count;

            if (CheckStatus::Up->value === $row['status']) {
                $perMonitor[$monitorId]['up'] += $count;
                $fleetUp += $count;
            }
        }

        return [$perMonitor, $fleetUp, $fleetTotal];
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
     * Bucket cross-monitor status rows into per-monitor, per-day worst-status ranks.
     *
     * @param list<array{monitorId: string, checkedAt: \DateTimeImmutable, status: CheckStatus}> $rows
     *
     * @return array<string, array<int, int>> monitorId => (dayIndex => worst rank)
     */
    private function foldDailyStatus(array $rows, \DateTimeImmutable $windowStart, int $days): array
    {
        $windowStartTs = $windowStart->getTimestamp();
        $worst = [];

        foreach ($rows as $row) {
            $index = intdiv($row['checkedAt']->getTimestamp() - $windowStartTs, self::DAY_SECONDS);
            if ($index < 0 || $index >= $days) {
                continue;
            }

            $monitorId = $row['monitorId'];
            $rank = $this->statusRank($row['status']);
            if (!isset($worst[$monitorId][$index]) || $rank > $worst[$monitorId][$index]) {
                $worst[$monitorId][$index] = $rank;
            }
        }

        return $worst;
    }

    /**
     * The day labels for a strip, oldest first.
     *
     * @return list<string>
     */
    private function dayLabels(\DateTimeImmutable $windowStart, int $days): array
    {
        $labels = [];
        for ($index = 0; $index < $days; ++$index) {
            $labels[] = $windowStart->add(new \DateInterval('P'.$index.'D'))->format('Y-m-d');
        }

        return $labels;
    }

    /**
     * Assemble one monitor's strip from the shared labels and its worst-rank map.
     *
     * @param list<string>    $labels
     * @param array<int, int> $worstByDay
     *
     * @return list<array{day: string, status: string|null}>
     */
    private function dailyStrip(array $labels, array $worstByDay): array
    {
        $strip = [];
        foreach ($labels as $index => $day) {
            $strip[] = [
                'day' => $day,
                'status' => $this->rankStatus($worstByDay[$index] ?? null),
            ];
        }

        return $strip;
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
     * array. (Copied from MonitorHistoryProvider; there is no shared base.).
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

    private function statusRank(CheckStatus $status): int
    {
        return match ($status) {
            CheckStatus::Up => 0,
            CheckStatus::Degraded => 1,
            CheckStatus::Down => 2,
        };
    }

    private function rankStatus(?int $rank): ?string
    {
        return match ($rank) {
            0 => CheckStatus::Up->value,
            1 => CheckStatus::Degraded->value,
            2 => CheckStatus::Down->value,
            default => null,
        };
    }
}
