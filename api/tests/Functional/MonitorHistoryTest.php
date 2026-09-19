<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\CheckStatus;
use App\Tests\Factory\CheckResultFactory;
use App\Tests\Factory\CheckRollupFactory;
use App\Tests\Factory\IncidentFactory;
use App\Tests\Factory\MonitorFactory;
use App\Tests\Factory\UserFactory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Exercises the aggregate history endpoint: the window, counts, uptime, median and
 * p95 over a seeded window; the fixed 48-bucket series and its placement; the ten
 * newest checks; the null-not-error shape for a monitor with no results; and the
 * 404 and 401 paths.
 */
final class MonitorHistoryTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    // Opt into API Platform 5.0's behaviour now, so createClient() booting the
    // kernel does not trigger a deprecation the suite is configured to fail on.
    protected static ?bool $alwaysBootKernel = true;

    private const string JSON_API = 'application/vnd.api+json';

    public function testHistoryReturnsCountsUptimeMedianAndTheBucketedSeries(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['url' => 'https://history.example.com']);
        $now = new \DateTimeImmutable();

        // Four passing checks with known latencies and one failure, each an hour
        // apart so they land in distinct half-hour buckets.
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 100, 'checkedAt' => $now->modify('-1 hour')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 200, 'checkedAt' => $now->modify('-2 hours')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 300, 'checkedAt' => $now->modify('-3 hours')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 400, 'checkedAt' => $now->modify('-4 hours')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Down, 'latencyMs' => null, 'httpStatusCode' => null, 'checkedAt' => $now->modify('-5 hours')]);
        // Outside the 24-hour window — must not be counted.
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 999, 'checkedAt' => $now->modify('-30 hours')]);

        // One incident inside 30 days, one outside.
        IncidentFactory::createOne(['monitor' => $monitor, 'startedAt' => $now->modify('-2 days')]);
        IncidentFactory::createOne(['monitor' => $monitor, 'startedAt' => $now->modify('-40 days')]);

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/monitors/'.$monitor->getId().'/history', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data']['attributes'];

        $this->assertSame(5, $attributes['checkCount']);
        $this->assertSame(4, $attributes['upCount']);
        $this->assertSame(0, $attributes['degradedCount']);
        $this->assertSame(1, $attributes['downCount']);
        $this->assertEqualsWithDelta(0.8, $attributes['uptimeRatio'], 0.0001);
        // Nearest-rank over [100,200,300,400]: p50 → 200, p95 → 400.
        $this->assertSame(200, $attributes['medianLatencyMs']);
        $this->assertSame(400, $attributes['p95LatencyMs']);
        $this->assertSame(1, $attributes['incidentCount30d']);

        // A fixed 48 half-hour buckets, each 1800s after the last.
        $series = $attributes['series'];
        $this->assertCount(48, $series);
        $first = new \DateTimeImmutable($series[0]['bucketStart']);
        $second = new \DateTimeImmutable($series[1]['bucketStart']);
        $this->assertSame(1800, $second->getTimestamp() - $first->getTimestamp());

        // Five checks in five distinct buckets: four up, one down; the rest empty.
        $withStatus = array_values(array_filter($series, static fn (array $bucket): bool => null !== $bucket['status']));
        $this->assertCount(5, $withStatus);
        $this->assertCount(4, array_filter($withStatus, static fn (array $bucket): bool => 'up' === $bucket['status']));
        $this->assertCount(1, array_filter($withStatus, static fn (array $bucket): bool => 'down' === $bucket['status']));

        // The four latency buckets carry the seeded medians; the down bucket is null.
        $bucketMedians = array_filter(array_column($series, 'medianLatencyMs'), static fn (?int $value): bool => null !== $value);
        sort($bucketMedians);
        $this->assertSame([100, 200, 300, 400], array_values($bucketMedians));

        // A bucket with no check breaks the line: null latency and null status.
        $empty = array_values(array_filter($series, static fn (array $bucket): bool => null === $bucket['status']))[0];
        $this->assertNull($empty['medianLatencyMs']);

        // windowEnd is ~24h after windowStart.
        $windowStart = new \DateTimeImmutable($attributes['windowStart']);
        $windowEnd = new \DateTimeImmutable($attributes['windowEnd']);
        $this->assertSame(48 * 1800, $windowEnd->getTimestamp() - $windowStart->getTimestamp());
    }

    public function testHistoryReturnsTheDailyStripAndTheSevenAndThirtyDayUptime(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['url' => 'https://strip.example.com']);
        $now = new \DateTimeImmutable();

        // Two checks today (inside 24h/7d/30d), then checks reaching further back so
        // each uptime window and several strip days can be checked independently.
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 100, 'checkedAt' => $now->modify('-1 hour')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Down, 'latencyMs' => null, 'httpStatusCode' => null, 'checkedAt' => $now->modify('-2 hours')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 200, 'checkedAt' => $now->modify('-3 days')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Down, 'latencyMs' => null, 'httpStatusCode' => null, 'checkedAt' => $now->modify('-10 days')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 300, 'checkedAt' => $now->modify('-40 days')]);

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/monitors/'.$monitor->getId().'/history', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data']['attributes'];

        // 7d window sees -1h,-2h,-3d: up 2, down 1 → 2/3.
        $this->assertEqualsWithDelta(2 / 3, $attributes['uptimeRatio7d'], 0.0001);
        // 30d window adds -10d (down): up 2, down 2 → 1/2. -40d is outside 30d.
        $this->assertEqualsWithDelta(0.5, $attributes['uptimeRatio30d'], 0.0001);

        // A 90-day strip, worst-status-wins per calendar day, null on empty days.
        $strip = $attributes['dailyStatus'];
        $this->assertCount(90, $strip);
        $byDay = array_column($strip, 'status', 'day');
        $today = new \DateTimeImmutable('today');
        $day = static fn (int $ago): string => $today->modify("-{$ago} days")->format('Y-m-d');
        $this->assertSame('up', $byDay[$day(3)]);
        $this->assertSame('down', $byDay[$day(10)]);
        $this->assertSame('up', $byDay[$day(40)]);
        $this->assertNull($byDay[$day(5)], 'A day with no check carries a null status.');
    }

    public function testLongWindowFiguresReadFromRollupsAfterRawExpiry(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['url' => 'https://rollup-history.example.com']);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        // Sixty days of hourly rollups (one per day is enough — the reader does not need
        // every hour present), all up, except a down on the day 50 days back: a day well
        // beyond the 7-day raw window and the 30-day uptime window, so its status and the
        // ratios can only come from rollups.
        for ($daysAgo = 1; $daysAgo <= 60; ++$daysAgo) {
            CheckRollupFactory::createOne([
                'monitor' => $monitor,
                'bucketStart' => $today->modify("-{$daysAgo} days")->setTime(12, 0),
                'upCount' => 5,
                'downCount' => 50 === $daysAgo ? 3 : 0,
                'latencyAvgMs' => 120,
            ]);
        }

        // Raw rows only for the last couple of hours — the tail past the newest rollup.
        $now = new \DateTimeImmutable();
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 110, 'checkedAt' => $now->modify('-1 hour')]);
        CheckResultFactory::createOne(['monitor' => $monitor, 'status' => CheckStatus::Up, 'latencyMs' => 130, 'checkedAt' => $now->modify('-2 hours')]);

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/monitors/'.$monitor->getId().'/history', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data']['attributes'];

        // 30-day uptime is a real figure, not collapsed to the raw tail: thirty up-only
        // rollups plus the two up raw rows.
        $this->assertNotNull($attributes['uptimeRatio30d']);
        $this->assertEqualsWithDelta(1.0, $attributes['uptimeRatio30d'], 0.0001);
        $this->assertNotNull($attributes['uptimeRatio7d']);

        // The 90-day strip carries status on days far older than the raw window.
        $strip = $attributes['dailyStatus'];
        $this->assertCount(90, $strip);
        $byDay = array_column($strip, 'status', 'day');
        $day = static fn (int $ago): string => $today->modify("-{$ago} days")->format('Y-m-d');
        $this->assertSame('down', $byDay[$day(50)], 'A rollup-only day still carries its status.');
        $this->assertSame('up', $byDay[$day(40)]);
        $this->assertNotNull($byDay[$day(20)], 'Days beyond raw retention are not blank.');
        // Today is filled by the raw tail, not a rollup.
        $this->assertSame('up', $byDay[$day(0)]);
    }

    public function testRecentChecksAreTheTenNewestNewestFirst(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['url' => 'https://recent.example.com']);
        $now = new \DateTimeImmutable();

        for ($minutes = 1; $minutes <= 12; ++$minutes) {
            CheckResultFactory::createOne([
                'monitor' => $monitor,
                'status' => CheckStatus::Up,
                'latencyMs' => $minutes,
                'checkedAt' => $now->modify("-{$minutes} minutes"),
            ]);
        }

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/monitors/'.$monitor->getId().'/history', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $recent = $response->toArray()['data']['attributes']['recentChecks'];
        $this->assertCount(10, $recent, 'Only the ten newest are returned.');
        // Newest first: the 1-minute-ago check leads, latency 1.
        $this->assertSame(1, $recent[0]['latencyMs']);
        $this->assertSame(2, $recent[1]['latencyMs']);
    }

    public function testMonitorWithNoResultsReturnsNullsAndZeroes(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['url' => 'https://empty.example.com']);

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/monitors/'.$monitor->getId().'/history', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data']['attributes'];
        $this->assertSame(0, $attributes['checkCount']);
        $this->assertNull($attributes['uptimeRatio']);
        $this->assertNull($attributes['medianLatencyMs']);
        $this->assertNull($attributes['p95LatencyMs']);
        $this->assertSame([], $attributes['recentChecks']);
        $this->assertCount(48, $attributes['series']);
        $this->assertNull($attributes['series'][0]['status']);
        $this->assertNull($attributes['uptimeRatio7d']);
        $this->assertNull($attributes['uptimeRatio30d']);
        $this->assertCount(90, $attributes['dailyStatus']);
        $this->assertNull($attributes['dailyStatus'][0]['status']);
    }

    public function testUnknownMonitorReturnsNotFound(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $token = $this->login($client, 'operator');

        $client->request(
            'GET',
            '/api/monitors/00000000-0000-0000-0000-000000000000/history',
            $this->readOptions($token),
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testHistoryWithoutATokenIsUnauthorized(): void
    {
        $client = static::createClient();
        $monitor = MonitorFactory::createOne(['url' => 'https://noauth-history.example.com']);

        $client->request(
            'GET',
            '/api/monitors/'.$monitor->getId().'/history',
            ['headers' => ['Accept' => self::JSON_API]],
        );
        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * Log in over HTTP and return the issued JWT.
     */
    private function login(Client $client, string $username): string
    {
        $response = $client->request('POST', '/auth/login', [
            'json' => ['username' => $username, 'password' => UserFactory::DEFAULT_PASSWORD],
        ]);

        return $response->toArray()['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function readOptions(string $token): array
    {
        return [
            'headers' => [
                'Accept' => self::JSON_API,
                'Authorization' => 'Bearer '.$token,
            ],
        ];
    }
}
