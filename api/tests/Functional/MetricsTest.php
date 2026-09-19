<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\CheckStatus;
use App\Enum\IncidentSeverity;
use App\Tests\Factory\CheckResultFactory;
use App\Tests\Factory\IncidentFactory;
use App\Tests\Factory\MonitorFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Exercises the fleet-wide overview aggregate at /api/metrics: the headline counts
 * derived from each monitor's denormalised last status, the 30-day uptime and 24-hour
 * response aggregates, the 48-bucket response series, the per-monitor rollup with its
 * 45-day daily strip, the open-incident tally and the recent-incident feed; plus the
 * empty-data shape and the 401 without a token.
 */
final class MetricsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    // Opt into API Platform 5.0's behaviour now, so createClient() booting the
    // kernel does not trigger a deprecation the suite is configured to fail on.
    protected static ?bool $alwaysBootKernel = true;

    private const string JSON_API = 'application/vnd.api+json';

    public function testMetricsReturnsCountsAggregatesSeriesRollupsAndIncidents(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $now = new \DateTimeImmutable();

        // Four monitors covering every count bucket. The counts come from the
        // denormalised last status and enabled flag alone, not from any check row.
        $up = MonitorFactory::createOne(['name' => 'Up One', 'url' => 'https://up.example.com', 'enabled' => true, 'lastStatus' => CheckStatus::Up]);
        $down = MonitorFactory::createOne(['name' => 'Down One', 'url' => 'https://down.example.com', 'enabled' => true, 'lastStatus' => CheckStatus::Down]);
        MonitorFactory::createOne(['name' => 'Paused One', 'url' => 'https://paused.example.com', 'enabled' => false, 'lastStatus' => CheckStatus::Up]);
        MonitorFactory::createOne(['name' => 'Fresh One', 'url' => 'https://fresh.example.com', 'enabled' => true, 'lastStatus' => null]);

        // Every check hangs off the one "Up One" monitor, so the fleet aggregates
        // equal that monitor's numbers and the arithmetic stays legible.
        // Inside 24h — drive the response series, the fleet average and the strip's
        // most-recent day.
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 100, 'checkedAt' => $now->modify('-1 hour')]);
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 200, 'checkedAt' => $now->modify('-2 hours')]);
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 300, 'checkedAt' => $now->modify('-3 hours')]);
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 400, 'checkedAt' => $now->modify('-4 hours')]);
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Down, 'latencyMs' => null, 'httpStatusCode' => null, 'checkedAt' => $now->modify('-5 hours')]);
        // Inside 30d, outside 24h — count toward uptime and the daily strip only.
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 500, 'checkedAt' => $now->modify('-10 days')]);
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Down, 'latencyMs' => null, 'httpStatusCode' => null, 'checkedAt' => $now->modify('-3 days')]);
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 250, 'checkedAt' => $now->modify('-3 days')]);
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 250, 'checkedAt' => $now->modify('-5 days')]);
        // Outside 30d but still inside the 45-day strip window.
        CheckResultFactory::createOne(['monitor' => $up, 'status' => CheckStatus::Up, 'latencyMs' => 999, 'checkedAt' => $now->modify('-40 days')]);

        // One open incident on each of two monitors, plus one resolved — for the open
        // tally and the newest-first feed.
        IncidentFactory::createOne(['monitor' => $down, 'startedAt' => $now->modify('-1 hour'), 'endedAt' => null, 'severity' => IncidentSeverity::Down, 'cause' => 'HTTP 503.']);
        IncidentFactory::createOne(['monitor' => $up, 'startedAt' => $now->modify('-2 days'), 'endedAt' => $now->modify('-2 days')->modify('+10 minutes'), 'severity' => IncidentSeverity::Down, 'cause' => 'Timeout.']);
        IncidentFactory::createOne(['monitor' => $up, 'startedAt' => $now->modify('-30 minutes'), 'endedAt' => null, 'severity' => IncidentSeverity::Down, 'cause' => 'Connection refused.']);

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/metrics', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data']['attributes'];

        // Headline counts, straight from last status + enabled.
        $this->assertSame(4, $attributes['monitorsTotal']);
        $this->assertSame(1, $attributes['monitorsUp']);
        $this->assertSame(1, $attributes['monitorsPaused']);
        $this->assertSame(1, $attributes['needingAttention']);

        // Fleet uptime over 30d: up c1,c2,c3,c4,c6(-10d),c8(-3d),c9(-5d) = 7,
        // down c5,c7(-3d) = 2; c10(-40d) is outside 30d. 7 / 9.
        $this->assertEqualsWithDelta(7 / 9, $attributes['uptimeRatio30d'], 0.0001);
        // Fleet average latency over 24h: mean of [100,200,300,400] = 250.
        $this->assertSame(250, $attributes['avgResponseMs']);
        $this->assertSame(2, $attributes['openIncidents']);

        // A fixed 48 half-hour buckets, each 1800s after the last.
        $series = $attributes['responseSeries'];
        $this->assertCount(48, $series);
        $first = new \DateTimeImmutable($series[0]['bucketStart']);
        $second = new \DateTimeImmutable($series[1]['bucketStart']);
        $this->assertSame(1800, $second->getTimestamp() - $first->getTimestamp());
        // The four 24h up checks sit in four distinct buckets carrying their latency;
        // the down check's bucket has no timed sample.
        $bucketMedians = array_values(array_filter(array_column($series, 'medianLatencyMs'), static fn (?int $value): bool => null !== $value));
        sort($bucketMedians);
        $this->assertSame([100, 200, 300, 400], $bucketMedians);

        // The per-monitor rollup for "Up One".
        $rollup = $this->rollupFor($attributes['monitors'], (string) $up->getId());
        $this->assertSame('Up One', $rollup['name']);
        $this->assertSame('https://up.example.com', $rollup['url']);
        $this->assertTrue($rollup['enabled']);
        $this->assertSame('up', $rollup['lastStatus']);
        // 24h average latency for this monitor: 250. 30d uptime: 7 / 9.
        $this->assertSame(250, $rollup['avgLatencyMs']);
        $this->assertEqualsWithDelta(7 / 9, $rollup['uptimeRatio30d'], 0.0001);

        // The 45-day strip: worst-status-wins per calendar day, null on empty days.
        $strip = $rollup['dailyStatus'];
        $this->assertCount(45, $strip);
        $byDay = array_column($strip, 'status', 'day');
        $today = new \DateTimeImmutable('today');
        $day = static fn (int $ago): string => $today->modify("-{$ago} days")->format('Y-m-d');
        // -3d saw an up and a down check: down wins. -5d: up. -4d: no check.
        $this->assertSame('down', $byDay[$day(3)]);
        $this->assertSame('up', $byDay[$day(5)]);
        $this->assertNull($byDay[$day(4)]);

        // A monitor with no checks rolls up to nulls.
        $downRollup = $this->rollupFor($attributes['monitors'], (string) $down->getId());
        $this->assertNull($downRollup['avgLatencyMs']);
        $this->assertNull($downRollup['uptimeRatio30d']);

        // The recent-incident feed, newest first, capped well under the limit.
        $incidents = $attributes['recentIncidents'];
        $this->assertCount(3, $incidents);
        $this->assertSame('Up One', $incidents[0]['monitorName']);
        $this->assertSame('down', $incidents[0]['severity']);
        $this->assertSame('Connection refused.', $incidents[0]['cause']);
        $this->assertNull($incidents[0]['endedAt']);
        $this->assertSame('Down One', $incidents[1]['monitorName']);
        $this->assertSame('HTTP 503.', $incidents[1]['cause']);
        // The oldest of the three is the resolved one, so it carries an end.
        $this->assertNotNull($incidents[2]['endedAt']);
        $this->assertSame('Timeout.', $incidents[2]['cause']);
    }

    public function testMetricsWithNoChecksReturnsNullsAndZeroesButAFullSeries(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['name' => 'Silent One', 'url' => 'https://silent.example.com', 'enabled' => true, 'lastStatus' => null]);

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/metrics', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data']['attributes'];

        $this->assertSame(1, $attributes['monitorsTotal']);
        $this->assertSame(0, $attributes['monitorsUp']);
        $this->assertSame(0, $attributes['monitorsPaused']);
        $this->assertSame(0, $attributes['needingAttention']);
        $this->assertNull($attributes['uptimeRatio30d']);
        $this->assertNull($attributes['avgResponseMs']);
        $this->assertSame(0, $attributes['openIncidents']);

        // The series is always 48 buckets; with no data every median is null.
        $this->assertCount(48, $attributes['responseSeries']);
        $this->assertNull($attributes['responseSeries'][0]['medianLatencyMs']);

        // The lone monitor rolls up to nulls with a full 45-day null strip.
        $this->assertCount(1, $attributes['monitors']);
        $rollup = $attributes['monitors'][0];
        $this->assertSame((string) $monitor->getId(), $rollup['monitorId']);
        $this->assertNull($rollup['avgLatencyMs']);
        $this->assertNull($rollup['uptimeRatio30d']);
        $this->assertCount(45, $rollup['dailyStatus']);
        $this->assertNull($rollup['dailyStatus'][0]['status']);

        $this->assertSame([], $attributes['recentIncidents']);
    }

    public function testOpenIncidentsIgnoresIncidentsOfSoftDeletedMonitors(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $now = new \DateTimeImmutable();

        $live = MonitorFactory::createOne(['name' => 'Live', 'url' => 'https://live.example.com', 'enabled' => true, 'lastStatus' => CheckStatus::Down]);
        $gone = MonitorFactory::createOne(['name' => 'Gone', 'url' => 'https://gone.example.com', 'enabled' => true, 'lastStatus' => CheckStatus::Down]);

        // One open incident on each monitor.
        IncidentFactory::createOne(['monitor' => $live, 'startedAt' => $now->modify('-1 hour'), 'endedAt' => null, 'severity' => IncidentSeverity::Down, 'cause' => 'Live is down.']);
        IncidentFactory::createOne(['monitor' => $gone, 'startedAt' => $now->modify('-1 hour'), 'endedAt' => null, 'severity' => IncidentSeverity::Down, 'cause' => 'Gone is down.']);

        // Soft-delete one monitor: Gedmo stamps deletedAt and the global filter hides
        // it and its incidents from every read.
        $entityManager = $this->entityManager();
        $entityManager->remove($gone);
        $entityManager->flush();

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/metrics', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data']['attributes'];

        // The deleted monitor's open incident must not be counted, matching the feed —
        // both exclude soft-deleted monitors, so the two never disagree.
        $this->assertSame(1, $attributes['openIncidents']);
        $this->assertCount(1, $attributes['recentIncidents']);
        $this->assertSame('Live', $attributes['recentIncidents'][0]['monitorName']);
    }

    public function testMetricsWithoutATokenIsUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/metrics', ['headers' => ['Accept' => self::JSON_API]]);
        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * @param list<array<string, mixed>> $rollups
     *
     * @return array<string, mixed>
     */
    private function rollupFor(array $rollups, string $monitorId): array
    {
        foreach ($rollups as $rollup) {
            if ($rollup['monitorId'] === $monitorId) {
                return $rollup;
            }
        }

        self::fail('No rollup for monitor '.$monitorId);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
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
