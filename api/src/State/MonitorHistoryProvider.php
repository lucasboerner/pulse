<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MonitorHistoryResource;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use App\Repository\CheckResultRepository;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;

/**
 * Builds the detail page's aggregate for one monitor over a fixed 24-hour window.
 *
 * All the arithmetic — counts, uptime, median, p95 and the 48 half-hour buckets —
 * runs in PHP over the scalar rows the repository returns, never in SQL: the test
 * suite is SQLite while production is PostgreSQL, and PERCENTILE_CONT / date_trunc
 * exist on only one of them. A sorted array of integers gives the median and the
 * p95 portably, and bucketing by timestamp costs nothing at these row counts.
 *
 * A missing or soft-deleted monitor returns null, which API Platform turns into a
 * 404.
 *
 * @implements ProviderInterface<MonitorHistoryResource>
 */
final readonly class MonitorHistoryProvider implements ProviderInterface
{
    private const int BUCKET_COUNT = 48;
    private const int BUCKET_SECONDS = 1800;
    private const int RECENT_LIMIT = 10;
    private const int INCIDENT_WINDOW_DAYS = 30;

    public function __construct(
        private MonitorRepository $monitors,
        private CheckResultRepository $checkResults,
        private IncidentRepository $incidents,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?MonitorHistoryResource
    {
        $monitorId = $uriVariables['monitorId'] ?? null;
        if (!\is_string($monitorId)) {
            return null;
        }

        // The global soft-delete filter makes this return null for a deleted monitor.
        $monitor = $this->monitors->find($monitorId);
        if (null === $monitor) {
            return null;
        }

        $windowEnd = new \DateTimeImmutable();
        $windowStart = $windowEnd->sub(new \DateInterval('PT'.(self::BUCKET_COUNT * self::BUCKET_SECONDS).'S'));
        $windowStartTs = $windowStart->getTimestamp();

        $rows = $this->checkResults->windowFor($monitor, $windowStart);

        $resource = new MonitorHistoryResource();
        $resource->monitorId = (string) $monitor->getId();
        $resource->windowStart = $windowStart;
        $resource->windowEnd = $windowEnd;
        $resource->incidentCount30d = $this->incidents->countStartedSince(
            $monitor,
            $windowEnd->sub(new \DateInterval('P'.self::INCIDENT_WINDOW_DAYS.'D')),
        );
        $resource->recentChecks = $this->recentChecks($monitor);

        // Per-bucket latencies and statuses, plus the window-wide tallies.
        /** @var list<list<int>> $bucketLatencies */
        $bucketLatencies = array_fill(0, self::BUCKET_COUNT, []);
        /** @var list<int|null> $bucketWorstRank a null bucket has seen no check */
        $bucketWorstRank = array_fill(0, self::BUCKET_COUNT, null);
        /** @var list<int> $windowLatencies */
        $windowLatencies = [];

        foreach ($rows as $row) {
            ++$resource->checkCount;
            match ($row['status']) {
                CheckStatus::Up => ++$resource->upCount,
                CheckStatus::Degraded => ++$resource->degradedCount,
                CheckStatus::Down => ++$resource->downCount,
            };

            $index = intdiv($row['checkedAt']->getTimestamp() - $windowStartTs, self::BUCKET_SECONDS);
            $index = max(0, min(self::BUCKET_COUNT - 1, $index));

            $rank = $this->statusRank($row['status']);
            if (null === $bucketWorstRank[$index] || $rank > $bucketWorstRank[$index]) {
                $bucketWorstRank[$index] = $rank;
            }

            if (null !== $row['latencyMs']) {
                $bucketLatencies[$index][] = $row['latencyMs'];
                $windowLatencies[] = $row['latencyMs'];
            }
        }

        $resource->uptimeRatio = $resource->checkCount > 0
            ? $resource->upCount / $resource->checkCount
            : null;

        sort($windowLatencies);
        $resource->medianLatencyMs = $this->percentile($windowLatencies, 50);
        $resource->p95LatencyMs = $this->percentile($windowLatencies, 95);

        $series = [];
        for ($index = 0; $index < self::BUCKET_COUNT; ++$index) {
            $latencies = $bucketLatencies[$index];
            sort($latencies);
            $series[] = [
                'bucketStart' => $windowStart->add(new \DateInterval('PT'.($index * self::BUCKET_SECONDS).'S')),
                'medianLatencyMs' => $this->percentile($latencies, 50),
                'status' => $this->rankStatus($bucketWorstRank[$index]),
            ];
        }
        $resource->series = $series;

        return $resource;
    }

    /**
     * @return list<array{checkedAt: \DateTimeImmutable, status: string, httpStatusCode: int|null, latencyMs: int|null, errorMessage: string|null}>
     */
    private function recentChecks(Monitor $monitor): array
    {
        $checks = [];
        foreach ($this->checkResults->recentFor($monitor, self::RECENT_LIMIT) as $result) {
            $checks[] = [
                'checkedAt' => $result->getCheckedAt(),
                'status' => $result->getStatus()->value,
                'httpStatusCode' => $result->getHttpStatusCode(),
                'latencyMs' => $result->getLatencyMs(),
                'errorMessage' => $result->getErrorMessage(),
            ];
        }

        return $checks;
    }

    /**
     * The value at the given percentile of a sorted integer array, by the
     * nearest-rank method — integer in, integer out, no interpolation, identical on
     * both database engines. Null for an empty array.
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
