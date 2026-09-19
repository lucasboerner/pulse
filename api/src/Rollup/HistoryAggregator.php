<?php

declare(strict_types=1);

namespace App\Rollup;

use App\Enum\CheckStatus;

/**
 * The one place the long-window reads and the rollup job agree on how history folds.
 *
 * Both providers now build their 7/30/90-day figures from hourly rollups plus a short
 * raw tail, and the rollup job needs the same nearest-rank percentile the detail
 * provider defined for its 24-hour p95. Rather than a third copy of each, that shared
 * arithmetic lives here: the nearest-rank percentile, the up/total fold, and the
 * per-day worst-status fold. A rollup bucket and the raw tail must not overlap — the
 * caller reads raw only after the newest rolled-up bucket — so folding both never
 * double-counts an hour.
 */
final class HistoryAggregator
{
    private const int DAY_SECONDS = 86400;

    /**
     * The value at the given percentile of a sorted integer array, by the nearest-rank
     * method — integer in, integer out, no interpolation, identical on both database
     * engines. Null for an empty array. The same definition MonitorHistoryProvider uses
     * for its 24-hour p95, so a rollup's p95 and a live p95 never diverge.
     *
     * @param list<int> $sorted
     */
    public function percentile(array $sorted, int $percentile): ?int
    {
        $count = \count($sorted);
        if (0 === $count) {
            return null;
        }

        $rank = (int) ceil($percentile / 100 * $count);
        $index = max(0, min($count - 1, $rank - 1));

        return $sorted[$index];
    }

    /**
     * The up and total check counts at or after $since, folding hourly rollup buckets
     * and the raw tail into one pair. Rollup buckets contribute their stored counts; raw
     * tail rows contribute one each. A caller divides up by total for an uptime ratio,
     * or sums pairs across monitors for a fleet figure.
     *
     * @param list<array{bucketStart: \DateTimeImmutable, upCount: int, degradedCount: int, downCount: int}> $rollupBuckets
     * @param list<array{checkedAt: \DateTimeImmutable, status: CheckStatus}>                                $rawTail
     *
     * @return array{up: int, total: int}
     */
    public function uptimeCounts(array $rollupBuckets, array $rawTail, \DateTimeImmutable $since): array
    {
        $up = 0;
        $total = 0;

        foreach ($rollupBuckets as $bucket) {
            if ($bucket['bucketStart'] < $since) {
                continue;
            }

            $total += $bucket['upCount'] + $bucket['degradedCount'] + $bucket['downCount'];
            $up += $bucket['upCount'];
        }

        foreach ($rawTail as $row) {
            if ($row['checkedAt'] < $since) {
                continue;
            }

            ++$total;
            if (CheckStatus::Up === $row['status']) {
                ++$up;
            }
        }

        return ['up' => $up, 'total' => $total];
    }

    /**
     * The calendar-day status strip over $days days from $windowStart, oldest first:
     * each day carries the worst status seen (down over degraded over up), or null when
     * neither a rollup bucket nor a raw check landed on it. A rollup bucket's own worst
     * status is derived by the same rule — any down, then any degraded, then any up.
     *
     * @param list<array{bucketStart: \DateTimeImmutable, upCount: int, degradedCount: int, downCount: int}> $rollupBuckets
     * @param list<array{checkedAt: \DateTimeImmutable, status: CheckStatus}>                                $rawTail
     *
     * @return list<array{day: string, status: string|null}>
     */
    public function dailyStrip(array $rollupBuckets, array $rawTail, \DateTimeImmutable $windowStart, int $days): array
    {
        $windowStartTs = $windowStart->getTimestamp();

        /** @var list<int|null> $worstRank a null day has seen nothing */
        $worstRank = array_fill(0, $days, null);

        foreach ($rollupBuckets as $bucket) {
            $index = intdiv($bucket['bucketStart']->getTimestamp() - $windowStartTs, self::DAY_SECONDS);
            if ($index < 0 || $index >= $days) {
                continue;
            }

            $rank = $this->bucketRank($bucket);
            if (null !== $rank && (null === $worstRank[$index] || $rank > $worstRank[$index])) {
                $worstRank[$index] = $rank;
            }
        }

        foreach ($rawTail as $row) {
            $index = intdiv($row['checkedAt']->getTimestamp() - $windowStartTs, self::DAY_SECONDS);
            if ($index < 0 || $index >= $days) {
                continue;
            }

            $rank = $this->statusRank($row['status']);
            if (null === $worstRank[$index] || $rank > $worstRank[$index]) {
                $worstRank[$index] = $rank;
            }
        }

        $strip = [];
        for ($index = 0; $index < $days; ++$index) {
            $strip[] = [
                'day' => $windowStart->add(new \DateInterval('P'.$index.'D'))->format('Y-m-d'),
                'status' => $this->rankStatus($worstRank[$index]),
            ];
        }

        return $strip;
    }

    /**
     * The worst status present in a rollup bucket, as a rank, or null when the bucket
     * holds no check at all.
     *
     * @param array{upCount: int, degradedCount: int, downCount: int} $bucket
     */
    private function bucketRank(array $bucket): ?int
    {
        if ($bucket['downCount'] > 0) {
            return $this->statusRank(CheckStatus::Down);
        }

        if ($bucket['degradedCount'] > 0) {
            return $this->statusRank(CheckStatus::Degraded);
        }

        if ($bucket['upCount'] > 0) {
            return $this->statusRank(CheckStatus::Up);
        }

        return null;
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
