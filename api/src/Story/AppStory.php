<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\Monitor;
use App\Enum\CheckStatus;
use App\Enum\IncidentSeverity;
use App\Tests\Factory\CheckResultFactory;
use App\Tests\Factory\CheckRollupFactory;
use App\Tests\Factory\IncidentFactory;
use App\Tests\Factory\MonitorFactory;
use App\Tests\Factory\MonitorGroupFactory;
use App\Tests\Factory\UserFactory;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\Persistence\flush_after;

/**
 * The demonstration fleet — development and screenshot data, never loaded
 * automatically. Load it into an empty database with:
 *
 *   docker compose exec api bin/console doctrine:fixtures:load
 *
 * Seven monitors across three groups: five healthy, one down with an open incident,
 * one paused. Each monitor carries ninety daily rollups — the permanent record behind
 * the 90-day status strip and the 7/30-day uptime figures — and a raw check every half
 * hour over the last day, which shapes the 24-hour latency chart and fills the
 * recent-checks list. Four incidents (three resolved, one ongoing) line up with the
 * failing days on the strips, so the overview and the detail pages tell one story.
 *
 * Everything is built through the Foundry factories the test suite already uses, so the
 * same defaults back both. The whole build runs inside one flush.
 *
 * The two operator accounts (ops, oncall) share UserFactory's default password, so a
 * developer can log in after loading; production accounts are still made only by
 * app:user:create.
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    /**
     * Days of the year, per monitor key, that carry a resolved outage — the same days
     * the resolved incidents below start on, so a red cell on the strip has an incident
     * behind it.
     *
     * @var array<string, list<int>>
     */
    private const array OUTAGE_DAYS = [
        'api' => [3],
        'web' => [26],
        'www' => [12],
        'billing' => [0],
    ];

    public function build(): void
    {
        flush_after(fn () => $this->seed());
    }

    private function seed(): void
    {
        $now = new \DateTimeImmutable();
        // Local midnight, exactly as the history providers compute their day windows,
        // so a daily rollup lands on the calendar day its bucket belongs to.
        $midnight = new \DateTimeImmutable('today');

        $groups = [
            'production' => MonitorGroupFactory::createOne(['name' => 'Production']),
            'marketing' => MonitorGroupFactory::createOne(['name' => 'Marketing']),
            'internal' => MonitorGroupFactory::createOne(['name' => 'Internal Tools']),
        ];

        $ops = UserFactory::createOne(['username' => 'ops', 'email' => 'ops@pulse.example.com']);
        $oncall = UserFactory::createOne(['username' => 'oncall', 'email' => 'oncall@pulse.example.com']);

        // key => [name, url, group, base latency in ms, profile, subscribers]
        /** @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: string, 5: list<object>}> $specs */
        $specs = [
            'api' => ['API', 'https://api.example.com/health', 'production', 70, 'healthy', [$ops, $oncall]],
            'web' => ['Web App', 'https://app.example.com', 'production', 140, 'healthy', [$ops]],
            'www' => ['Marketing Site', 'https://www.example.com', 'marketing', 110, 'healthy', []],
            'blog' => ['Blog', 'https://blog.example.com', 'marketing', 180, 'healthy', []],
            'status' => ['Status Page', 'https://status.example.com', 'internal', 90, 'healthy', []],
            'billing' => ['Billing Service', 'https://billing.example.com', 'production', 160, 'down', [$ops, $oncall]],
            'staging' => ['Staging', 'https://staging.example.com', 'internal', 200, 'paused', []],
        ];

        /** @var array<string, Monitor> $monitors */
        $monitors = [];
        foreach ($specs as $key => [$name, $url, $group, $latency, $profile, $subscribers]) {
            $enabled = 'paused' !== $profile;
            $lastStatus = 'down' === $profile ? CheckStatus::Down : CheckStatus::Up;
            $sinceLastCheck = match ($profile) {
                'down' => 'PT2M',
                'paused' => 'PT3H',
                default => 'PT1M',
            };

            $monitors[$key] = MonitorFactory::createOne([
                'name' => $name,
                'url' => $url,
                'monitorGroup' => $groups[$group],
                'enabled' => $enabled,
                'lastStatus' => $lastStatus,
                'lastCheckedAt' => $now->sub(new \DateInterval($sinceLastCheck)),
                'nextCheckAt' => $enabled ? $now->add(new \DateInterval('PT1M')) : $now,
                'subscribers' => $subscribers,
            ]);

            $this->seedRawResults($monitors[$key], $profile, $latency, $now);
            $this->seedRollups($monitors[$key], $latency, self::OUTAGE_DAYS[$key] ?? [], $midnight);
        }

        $this->seedIncidents($monitors, $now, $midnight);
    }

    /**
     * A raw check every half hour over the last 24 hours, so every half-hour bucket of
     * the response chart holds a point. Paused monitors stopped being checked three
     * hours ago; the down monitor's last ~40 minutes are failing checks, most recent
     * first, so the recent-checks list and the tail of the chart show the outage.
     */
    private function seedRawResults(Monitor $monitor, string $profile, int $baseLatency, \DateTimeImmutable $now): void
    {
        // Where the healthy history starts, measured in minutes before now: paused
        // monitors trail off three hours ago, the down monitor an hour ago (its recent
        // run is the outage below), the rest right up to now.
        $startMinutes = match ($profile) {
            'paused' => 180,
            'down' => 60,
            default => 0,
        };

        for ($minutes = $startMinutes; $minutes <= $startMinutes + 1440; $minutes += 30) {
            CheckResultFactory::createOne([
                'monitor' => $monitor,
                'status' => CheckStatus::Up,
                'latencyMs' => $this->jitter($baseLatency),
                'httpStatusCode' => 200,
                'checkedAt' => $now->sub(new \DateInterval('PT'.$minutes.'M')),
            ]);
        }

        if ('down' === $profile) {
            for ($minutes = 0; $minutes <= 40; $minutes += 10) {
                CheckResultFactory::createOne([
                    'monitor' => $monitor,
                    'status' => CheckStatus::Down,
                    'latencyMs' => $this->jitter(60),
                    'httpStatusCode' => 503,
                    'errorMessage' => 'HTTP 503 Service Unavailable',
                    'checkedAt' => $now->sub(new \DateInterval('PT'.$minutes.'M')),
                ]);
            }
        }
    }

    /**
     * Ninety daily rollups, newest first, one bucket per calendar day. A day is all-up
     * unless it is listed as an outage day, when a handful of the checks fail — enough
     * to colour that day's cell on the strip without dropping the uptime figure far.
     *
     * @param list<int> $outageDays
     */
    private function seedRollups(Monitor $monitor, int $baseLatency, array $outageDays, \DateTimeImmutable $midnight): void
    {
        for ($day = 0; $day < 90; ++$day) {
            $total = 288; // a check every five minutes
            $down = \in_array($day, $outageDays, true) ? random_int(4, 12) : 0;

            CheckRollupFactory::createOne([
                'monitor' => $monitor,
                'bucketStart' => $midnight->sub(new \DateInterval('P'.$day.'D')),
                'upCount' => $total - $down,
                'degradedCount' => 0,
                'downCount' => $down,
                'latencyMinMs' => max(20, $baseLatency - 30),
                'latencyAvgMs' => $baseLatency + 10,
                'latencyMaxMs' => $baseLatency + 250,
                'latencyP95Ms' => $baseLatency + 180,
            ]);
        }
    }

    /**
     * One ongoing outage (the current Billing failure) and three resolved ones, each
     * starting on the outage day seeded into its monitor's rollups above.
     *
     * @param array<string, Monitor> $monitors
     */
    private function seedIncidents(array $monitors, \DateTimeImmutable $now, \DateTimeImmutable $midnight): void
    {
        IncidentFactory::createOne([
            'monitor' => $monitors['billing'],
            'startedAt' => $now->sub(new \DateInterval('PT40M')),
            'endedAt' => null,
            'severity' => IncidentSeverity::Down,
            'cause' => 'HTTP 503 Service Unavailable',
            'notifiedOpenedAt' => $now->sub(new \DateInterval('PT39M')),
        ]);

        // key => [days ago, hour of day, outage length in minutes, cause]
        $resolved = [
            ['api', 3, 9, 22, 'Connection timed out'],
            ['www', 12, 14, 8, 'HTTP 500 Internal Server Error'],
            ['web', 26, 3, 15, 'HTTP 502 Bad Gateway'],
        ];

        foreach ($resolved as [$key, $daysAgo, $hour, $lengthMinutes, $cause]) {
            $startedAt = $midnight
                ->sub(new \DateInterval('P'.$daysAgo.'D'))
                ->add(new \DateInterval('PT'.$hour.'H'));
            $endedAt = $startedAt->add(new \DateInterval('PT'.$lengthMinutes.'M'));

            IncidentFactory::createOne([
                'monitor' => $monitors[$key],
                'startedAt' => $startedAt,
                'endedAt' => $endedAt,
                'severity' => IncidentSeverity::Down,
                'cause' => $cause,
                'notifiedOpenedAt' => $startedAt->add(new \DateInterval('PT1M')),
                'notifiedResolvedAt' => $endedAt->add(new \DateInterval('PT1M')),
            ]);
        }
    }

    /**
     * A latency around a monitor's baseline, floored so it never reads as implausibly
     * fast.
     */
    private function jitter(int $baseLatency): int
    {
        return max(20, $baseLatency + random_int(-25, 70));
    }
}
