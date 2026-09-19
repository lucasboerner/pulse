<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\MonitorHistoryProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One aggregate read for the detail page: the 24-hour window a monitor covers, its
 * counts and uptime, the median and p95 latency, a fixed 48-bucket response series
 * for the chart, the newest raw checks and the 30-day incident count.
 *
 * A computed resource, not entity-backed — MonitorHistoryProvider builds it. Nested
 * under the monitor because it only ever exists for one; there is no collection.
 */
#[ApiResource(
    shortName: 'MonitorHistory',
    operations: [
        new Get(
            uriTemplate: '/monitors/{monitorId}/history',
            uriVariables: ['monitorId'],
            provider: MonitorHistoryProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['history:read']],
)]
class MonitorHistoryResource
{
    #[ApiProperty(identifier: true)]
    public string $monitorId = '';

    #[Groups(['history:read'])]
    public \DateTimeImmutable $windowStart;

    #[Groups(['history:read'])]
    public \DateTimeImmutable $windowEnd;

    #[Groups(['history:read'])]
    public int $checkCount = 0;

    #[Groups(['history:read'])]
    public int $upCount = 0;

    #[Groups(['history:read'])]
    public int $degradedCount = 0;

    #[Groups(['history:read'])]
    public int $downCount = 0;

    /** The share of checks that passed over the 24-hour window, or null when it holds none. */
    #[Groups(['history:read'])]
    public ?float $uptimeRatio = null;

    /** The share of checks that passed over the last 7 days, or null when none ran. */
    #[Groups(['history:read'])]
    public ?float $uptimeRatio7d = null;

    /** The share of checks that passed over the last 30 days, or null when none ran. */
    #[Groups(['history:read'])]
    public ?float $uptimeRatio30d = null;

    #[Groups(['history:read'])]
    public ?int $medianLatencyMs = null;

    #[Groups(['history:read'])]
    public ?int $p95LatencyMs = null;

    /**
     * The chart points: 48 half-hour buckets over the window. A bucket with no
     * check carries a null latency and a null status, which breaks the chart line;
     * status is the worst seen in the bucket (down over degraded over up).
     *
     * @var list<array{bucketStart: \DateTimeImmutable, medianLatencyMs: int|null, status: string|null}>
     */
    #[Groups(['history:read'])]
    public array $series = [];

    /**
     * The newest raw results, newest first.
     *
     * @var list<array{checkedAt: \DateTimeImmutable, status: string, httpStatusCode: int|null, latencyMs: int|null, errorMessage: string|null}>
     */
    #[Groups(['history:read'])]
    public array $recentChecks = [];

    #[Groups(['history:read'])]
    public int $incidentCount30d = 0;

    /**
     * The 90-day status strip: one entry per calendar day, oldest first, carrying the
     * worst status seen that day (down over degraded over up). A day with no check
     * carries a null status.
     *
     * @var list<array{day: string, status: string|null}>
     */
    #[Groups(['history:read'])]
    public array $dailyStatus = [];
}
