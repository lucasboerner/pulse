<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\MetricsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The fleet-wide dashboard aggregate behind the overview: the headline counts, the
 * 30-day uptime and 24-hour response numbers, the open-incident tally, a cross-monitor
 * response series, a per-monitor rollup with a daily status strip, and the recent
 * incident feed.
 *
 * A computed resource, not entity-backed — MetricsProvider composes it from the
 * repositories. It is a singleton: there is only ever one fleet, so the single Get sits
 * at /metrics with no URI variable and a constant identifier ('current') that JSON:API
 * serialises as the resource id.
 *
 * Auth is enforced fleet-wide by the security.yaml access_control (every /api/* path
 * needs a full token), exactly as for MonitorHistoryResource, so no per-operation
 * security is declared here.
 */
#[ApiResource(
    shortName: 'Metrics',
    operations: [
        new Get(
            uriTemplate: '/metrics',
            provider: MetricsProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['metrics:read']],
)]
class MetricsResource
{
    /** A singleton needs a stable id so JSON:API has a data.id and the route needs no variable. */
    #[ApiProperty(identifier: true)]
    public string $id = 'current';

    #[Groups(['metrics:read'])]
    public int $monitorsTotal = 0;

    /** Enabled monitors whose last check passed. */
    #[Groups(['metrics:read'])]
    public int $monitorsUp = 0;

    /** Disabled monitors, whatever their last status. */
    #[Groups(['metrics:read'])]
    public int $monitorsPaused = 0;

    /** Enabled monitors whose last status is down or degraded. */
    #[Groups(['metrics:read'])]
    public int $needingAttention = 0;

    /** Fleet-wide 30-day uptime, or null when no check ran in the window. */
    #[Groups(['metrics:read'])]
    public ?float $uptimeRatio30d = null;

    /** Fleet-wide mean latency over the last 24 hours, or null when nothing was timed. */
    #[Groups(['metrics:read'])]
    public ?int $avgResponseMs = null;

    #[Groups(['metrics:read'])]
    public int $openIncidents = 0;

    /**
     * The overview chart points: 48 half-hour buckets over the last 24 hours,
     * cross-monitor. A bucket with no timed check carries a null median, which breaks
     * the chart line.
     *
     * @var list<array{bucketStart: \DateTimeImmutable, medianLatencyMs: int|null}>
     */
    #[Groups(['metrics:read'])]
    public array $responseSeries = [];

    /**
     * The "All Systems" rows: one rollup per monitor with its identity, last status,
     * 24-hour average latency, 30-day uptime and a 45-day daily status strip
     * (worst-status-wins per day; a day with no check is null).
     *
     * @var list<array{
     *     monitorId: string,
     *     name: string,
     *     url: string,
     *     enabled: bool,
     *     lastStatus: string|null,
     *     avgLatencyMs: int|null,
     *     uptimeRatio30d: float|null,
     *     dailyStatus: list<array{day: string, status: string|null}>
     * }>
     */
    #[Groups(['metrics:read'])]
    public array $monitors = [];

    /**
     * The recent-incident feed, newest first, capped at a handful. Each entry carries
     * its monitor's id as well as its name, so the overview can link an incident back
     * to the monitor it belongs to.
     *
     * @var list<array{monitorId: string, monitorName: string, severity: string, startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable|null, cause: string|null}>
     */
    #[Groups(['metrics:read'])]
    public array $recentIncidents = [];
}
