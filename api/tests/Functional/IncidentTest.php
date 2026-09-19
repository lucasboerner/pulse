<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Monitor;
use App\Enum\IncidentSeverity;
use App\Tests\Factory\IncidentFactory;
use App\Tests\Factory\MonitorFactory;
use App\Tests\Factory\UserFactory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Exercises the read-only incident collection over JSON:API: the monitor filter
 * reaching the Doctrine query through the DTO, the newest-first default order, the
 * exposed and withheld attributes, and the 401 without a token.
 */
final class IncidentTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    // Opt into API Platform 5.0's behaviour now, so createClient() booting the
    // kernel does not trigger a deprecation the suite is configured to fail on.
    protected static ?bool $alwaysBootKernel = true;

    private const string JSON_API = 'application/vnd.api+json';

    public function testCollectionFilteredByMonitorReturnsThatMonitorsIncidentsNewestFirst(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['url' => 'https://target.example.com']);
        $other = MonitorFactory::createOne(['url' => 'https://other.example.com']);

        $older = IncidentFactory::createOne([
            'monitor' => $monitor,
            'startedAt' => new \DateTimeImmutable('2026-09-14 04:00:00'),
            'endedAt' => new \DateTimeImmutable('2026-09-14 04:06:00'),
            'severity' => IncidentSeverity::Down,
            'cause' => 'Connection timed out.',
        ]);
        $newer = IncidentFactory::createOne([
            'monitor' => $monitor,
            'startedAt' => new \DateTimeImmutable('2026-09-16 09:30:00'),
            'endedAt' => null,
            'severity' => IncidentSeverity::Down,
            'cause' => 'HTTP 503.',
        ]);
        // An incident on a different monitor must not leak into the filtered result.
        IncidentFactory::createOne([
            'monitor' => $other,
            'startedAt' => new \DateTimeImmutable('2026-09-17 12:00:00'),
        ]);

        $token = $this->login($client, 'operator');
        $response = $client->request(
            'GET',
            '/api/incidents?monitor='.$monitor->getId(),
            $this->readOptions($token),
        );

        $this->assertResponseStatusCodeSame(200);
        $document = $response->toArray();
        $this->assertCount(2, $document['data'], 'Only the filtered monitor incidents are returned.');

        // Newest first: the ongoing incident precedes the older resolved one.
        $ids = array_map(
            fn (array $row): string => $this->idFromIri((string) $row['id']),
            $document['data'],
        );
        $this->assertSame(
            [(string) $newer->getId(), (string) $older->getId()],
            $ids,
            'Incidents are ordered by startedAt descending by default.',
        );
    }

    public function testPayloadExposesTheIncidentFieldsAndHidesTheNotifiedColumns(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $monitor = MonitorFactory::createOne(['url' => 'https://payload.example.com']);
        IncidentFactory::createOne([
            'monitor' => $monitor,
            'startedAt' => new \DateTimeImmutable('2026-09-16 09:30:00'),
            'endedAt' => new \DateTimeImmutable('2026-09-16 09:41:00'),
            'severity' => IncidentSeverity::Down,
            'cause' => 'HTTP 500.',
            'notifiedOpenedAt' => new \DateTimeImmutable('2026-09-16 09:30:05'),
            'notifiedResolvedAt' => new \DateTimeImmutable('2026-09-16 09:41:05'),
        ]);

        $token = $this->login($client, 'operator');
        $response = $client->request(
            'GET',
            '/api/incidents?monitor='.$monitor->getId(),
            $this->readOptions($token),
        );

        $this->assertResponseStatusCodeSame(200);
        $attributes = $response->toArray()['data'][0]['attributes'];
        $this->assertArrayHasKey('startedAt', $attributes);
        $this->assertArrayHasKey('endedAt', $attributes);
        $this->assertSame('down', $attributes['severity']);
        $this->assertSame('HTTP 500.', $attributes['cause']);
        $this->assertArrayNotHasKey('notifiedOpenedAt', $attributes);
        $this->assertArrayNotHasKey('notifiedResolvedAt', $attributes);

        // The monitor travels as a relationship, never the notified bookkeeping.
        $this->assertSame(
            $this->monitorIri($monitor),
            $response->toArray()['data'][0]['relationships']['monitor']['data']['id'],
        );
    }

    public function testCollectionWithoutATokenIsUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/incidents', ['headers' => ['Accept' => self::JSON_API]]);
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

    private function monitorIri(Monitor $monitor): string
    {
        return '/api/monitors/'.$monitor->getId();
    }

    private function idFromIri(string $iri): string
    {
        $position = strrpos($iri, '/');

        return false === $position ? $iri : substr($iri, $position + 1);
    }
}
