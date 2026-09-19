<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\CheckStatus;
use App\Tests\Factory\CheckResultFactory;
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
