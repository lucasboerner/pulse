<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\MonitorGroup;
use App\Entity\User;
use App\Message\RunCheck;
use App\Tests\Factory\MonitorFactory;
use App\Tests\Factory\MonitorGroupFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Exercises the monitor CRUD surface over JSON:API: the full operation set, the
 * validation that returns 422 naming the field, the subscriber set written on
 * create and update, and the soft delete.
 */
final class MonitorTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    // Opt into API Platform 5.0's behaviour now, so createClient() booting the
    // kernel does not trigger a deprecation the suite is configured to fail on.
    protected static ?bool $alwaysBootKernel = true;

    private const string JSON_API = 'application/vnd.api+json';

    public function testCreateReturnsCreatedSubscribesTheOperatorAndKeepsTheGroup(): void
    {
        $client = static::createClient();
        $operator = UserFactory::createOne(['username' => 'operator']);
        $subscriber = UserFactory::createOne();
        $group = MonitorGroupFactory::createOne(['name' => 'Production']);
        $token = $this->login($client, 'operator');

        $response = $client->request('POST', '/api/monitors', $this->writeOptions($token, [
            'data' => [
                'type' => 'Monitor',
                'attributes' => [
                    'name' => 'Public site',
                    'url' => 'https://example.com/health',
                    'type' => 'http',
                    'intervalSeconds' => 60,
                    'timeoutMs' => 8000,
                    'expectedStatusCode' => 200,
                    'enabled' => true,
                ],
                'relationships' => [
                    'monitorGroup' => [
                        'data' => ['type' => 'MonitorGroup', 'id' => $this->groupIri($group)],
                    ],
                    'subscribers' => [
                        'data' => [
                            ['type' => 'User', 'id' => $this->userIri($subscriber)],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertResponseStatusCodeSame(201);
        $document = $response->toArray();
        $this->assertSame('Public site', $document['data']['attributes']['name']);
        $this->assertSame($this->groupIri($group), $document['data']['relationships']['monitorGroup']['data']['id']);

        $subscribers = $this->subscriberIris($document);
        $this->assertContains($this->userIri($operator), $subscribers, 'The creating operator is subscribed.');
        $this->assertContains($this->userIri($subscriber), $subscribers, 'The supplied subscriber is kept.');
        $this->assertCount(2, $subscribers);

        // The join rows exist in monitor_subscriber for both.
        $this->assertSame(2, $this->joinRowCount($this->idFromIri($document['data']['id'])));
    }

    public function testCreatingAMonitorDispatchesOneCheck(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $token = $this->login($client, 'operator');

        $response = $client->request('POST', '/api/monitors', $this->writeOptions($token, [
            'data' => [
                'type' => 'Monitor',
                'attributes' => [
                    'name' => 'Checked on create',
                    'url' => 'https://create.example.com',
                    'type' => 'http',
                    'intervalSeconds' => 60,
                    'timeoutMs' => 8000,
                ],
            ],
        ]));
        $this->assertResponseStatusCodeSame(201);
        $monitorId = $this->idFromIri($response->toArray()['data']['id']);

        // The inline first check is dispatched to the async transport, which is
        // in-memory in tests — assert the dispatch, not the result.
        $transport = static::getContainer()->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(RunCheck::class, $message);
        $this->assertSame($monitorId, $message->monitorId);
    }

    public function testCreatingASecondMonitorWithTheSameUrlAndTypeIsUnprocessable(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $token = $this->login($client, 'operator');
        $body = [
            'data' => [
                'type' => 'Monitor',
                'attributes' => [
                    'name' => 'First',
                    'url' => 'https://duplicate.example.com',
                    'type' => 'http',
                    'intervalSeconds' => 60,
                    'timeoutMs' => 8000,
                ],
            ],
        ];

        $client->request('POST', '/api/monitors', $this->writeOptions($token, $body));
        $this->assertResponseStatusCodeSame(201);

        $response = $client->request('POST', '/api/monitors', $this->writeOptions($token, $body));
        $this->assertResponseStatusCodeSame(422);
        $this->assertViolationNamesField($response, 'url');
    }

    public function testIntervalBelowTheMinimumIsUnprocessable(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $token = $this->login($client, 'operator');

        $response = $client->request('POST', '/api/monitors', $this->writeOptions($token, [
            'data' => [
                'type' => 'Monitor',
                'attributes' => [
                    'name' => 'Too frequent',
                    'url' => 'https://interval.example.com',
                    'type' => 'http',
                    'intervalSeconds' => 10,
                    'timeoutMs' => 5000,
                ],
            ],
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertViolationNamesField($response, 'intervalSeconds');
    }

    public function testTimeoutLargerThanTheIntervalIsUnprocessable(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $token = $this->login($client, 'operator');

        $response = $client->request('POST', '/api/monitors', $this->writeOptions($token, [
            'data' => [
                'type' => 'Monitor',
                'attributes' => [
                    'name' => 'Slow check',
                    'url' => 'https://timeout.example.com',
                    'type' => 'http',
                    'intervalSeconds' => 30,
                    'timeoutMs' => 40000,
                ],
            ],
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertViolationNamesField($response, 'timeoutMs');
    }

    public function testCollectionListsMonitorsAndExcludesSoftDeletedOnes(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        MonitorFactory::createOne(['name' => 'Kept', 'url' => 'https://kept.example.com']);
        $removed = MonitorFactory::createOne(['name' => 'Removed', 'url' => 'https://removed.example.com']);

        // Gedmo turns the remove into a deletedAt stamp; the global filter then
        // hides the row from every read.
        $entityManager = $this->entityManager();
        $entityManager->remove($removed);
        $entityManager->flush();

        $token = $this->login($client, 'operator');
        $response = $client->request('GET', '/api/monitors', $this->readOptions($token));

        $this->assertResponseStatusCodeSame(200);
        $document = $response->toArray();
        $this->assertCount(1, $document['data']);
        $this->assertSame('Kept', $document['data'][0]['attributes']['name']);
    }

    public function testPatchChangesFieldsAndReplacesTheSubscriberSet(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $userA = UserFactory::createOne();
        $userB = UserFactory::createOne();
        $token = $this->login($client, 'operator');

        $created = $client->request('POST', '/api/monitors', $this->writeOptions($token, [
            'data' => [
                'type' => 'Monitor',
                'attributes' => [
                    'name' => 'Before',
                    'url' => 'https://patch.example.com',
                    'type' => 'http',
                    'intervalSeconds' => 60,
                    'timeoutMs' => 8000,
                    'enabled' => true,
                ],
                'relationships' => [
                    'subscribers' => [
                        'data' => [['type' => 'User', 'id' => $this->userIri($userA)]],
                    ],
                ],
            ],
        ]));
        $this->assertResponseStatusCodeSame(201);
        $iri = $created->toArray()['data']['id'];

        // Omitting the subscribers leaves the set untouched while the fields change.
        $patched = $client->request('PATCH', $iri, $this->writeOptions($token, [
            'data' => [
                'type' => 'Monitor',
                'id' => $iri,
                'attributes' => ['name' => 'After', 'enabled' => false],
            ],
        ]));
        $this->assertResponseStatusCodeSame(200);
        $document = $patched->toArray();
        $this->assertSame('After', $document['data']['attributes']['name']);
        $this->assertFalse($document['data']['attributes']['enabled']);
        $this->assertCount(2, $this->subscriberIris($document));

        // A supplied list replaces the whole set.
        $replaced = $client->request('PATCH', $iri, $this->writeOptions($token, [
            'data' => [
                'type' => 'Monitor',
                'id' => $iri,
                'relationships' => [
                    'subscribers' => [
                        'data' => [['type' => 'User', 'id' => $this->userIri($userB)]],
                    ],
                ],
            ],
        ]));
        $this->assertResponseStatusCodeSame(200);
        $subscribers = $this->subscriberIris($replaced->toArray());
        $this->assertSame([$this->userIri($userB)], $subscribers);
    }

    public function testDeleteSoftDeletesAndLetsTheSameTargetBeRecreated(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        $token = $this->login($client, 'operator');
        $body = [
            'data' => [
                'type' => 'Monitor',
                'attributes' => [
                    'name' => 'Doomed',
                    'url' => 'https://delete.example.com',
                    'type' => 'http',
                    'intervalSeconds' => 60,
                    'timeoutMs' => 8000,
                ],
            ],
        ];

        $created = $client->request('POST', '/api/monitors', $this->writeOptions($token, $body));
        $this->assertResponseStatusCodeSame(201);
        $iri = $created->toArray()['data']['id'];
        $monitorId = $this->idFromIri($iri);

        $client->request('DELETE', $iri, $this->readOptions($token));
        $this->assertResponseStatusCodeSame(204);

        // Gone from both reads.
        $client->request('GET', $iri, $this->readOptions($token));
        $this->assertResponseStatusCodeSame(404);
        $collection = $client->request('GET', '/api/monitors', $this->readOptions($token));
        $this->assertCount(0, $collection->toArray()['data']);

        // The row and its deletedAt stamp survive.
        $row = $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT deleted_at FROM monitor WHERE id = ?',
            [$monitorId],
        );
        $this->assertNotFalse($row, 'The row still exists after a soft delete.');
        $this->assertNotNull($row['deleted_at'], 'deleted_at is stamped.');

        // The same url and type can be created again.
        $client->request('POST', '/api/monitors', $this->writeOptions($token, $body));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testEveryOperationWithoutATokenIsUnauthorized(): void
    {
        $client = static::createClient();
        $monitor = MonitorFactory::createOne(['url' => 'https://noauth.example.com']);
        $iri = '/api/monitors/'.$monitor->getId();
        $writeBody = [
            'data' => [
                'type' => 'Monitor',
                'attributes' => ['name' => 'Nope', 'url' => 'https://nope.example.com'],
            ],
        ];

        $client->request('GET', '/api/monitors', $this->anonymousRead());
        $this->assertResponseStatusCodeSame(401);

        $client->request('GET', $iri, $this->anonymousRead());
        $this->assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/monitors', $this->anonymousWrite($writeBody));
        $this->assertResponseStatusCodeSame(401);

        $client->request('PATCH', $iri, $this->anonymousWrite(
            ['data' => ['type' => 'Monitor', 'id' => $iri, 'attributes' => ['name' => 'Nope']]],
        ));
        $this->assertResponseStatusCodeSame(401);

        $client->request('DELETE', $iri, $this->anonymousRead());
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
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function writeOptions(string $token, array $body): array
    {
        return [
            'headers' => [
                'Accept' => self::JSON_API,
                'Content-Type' => self::JSON_API,
                'Authorization' => 'Bearer '.$token,
            ],
            'body' => json_encode($body, \JSON_THROW_ON_ERROR),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function anonymousRead(): array
    {
        return ['headers' => ['Accept' => self::JSON_API]];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function anonymousWrite(array $body): array
    {
        return [
            'headers' => ['Accept' => self::JSON_API, 'Content-Type' => self::JSON_API],
            'body' => json_encode($body, \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function subscriberIris(array $document): array
    {
        return array_map(
            static fn (array $reference): string => (string) $reference['id'],
            $document['data']['relationships']['subscribers']['data'],
        );
    }

    private function userIri(User $user): string
    {
        return '/api/users/'.$user->getId();
    }

    private function groupIri(MonitorGroup $group): string
    {
        return '/api/monitor_groups/'.$group->getId();
    }

    private function idFromIri(string $iri): string
    {
        $position = strrpos($iri, '/');

        return false === $position ? $iri : substr($iri, $position + 1);
    }

    private function joinRowCount(string $monitorId): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM monitor_subscriber WHERE monitor_id = ?',
            [$monitorId],
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    private function assertViolationNamesField(ResponseInterface $response, string $field): void
    {
        $body = $response->toArray(false);
        $this->assertArrayHasKey('errors', $body);
        $this->assertStringContainsString($field, json_encode($body['errors'], \JSON_THROW_ON_ERROR));
    }
}
