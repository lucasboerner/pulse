<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\Factory\MonitorFactory;
use App\Tests\Factory\UserFactory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Exercises the authentication seam end to end: JWT login, the bearer-protected
 * monitors read and the public health probe.
 */
final class AuthenticationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    // Opt into API Platform 5.0's behaviour now, so createClient() booting the
    // kernel does not trigger a deprecation the suite is configured to fail on.
    protected static ?bool $alwaysBootKernel = true;

    private const string JSON_API = 'application/vnd.api+json';

    public function testHealthEndpointIsPublic(): void
    {
        $client = static::createClient();

        $response = $client->request('GET', '/health');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['status' => 'ok'], $response->toArray());
    }

    public function testLoginWithValidCredentialsReturnsJwt(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);

        $response = $client->request('POST', '/auth/login', [
            'json' => [
                'username' => 'operator',
                'password' => UserFactory::DEFAULT_PASSWORD,
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $response->toArray();
        $this->assertArrayHasKey('token', $body);
        // Three base64url segments separated by dots — a JSON Web Token.
        $this->assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $body['token']);
    }

    public function testLoginWithWrongPasswordReturnsUnauthorized(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);

        $client->request('POST', '/auth/login', [
            'json' => [
                'username' => 'operator',
                'password' => 'not-the-password',
            ],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMonitorsCollectionWithValidTokenReturnsJsonApiDocument(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'operator']);
        MonitorFactory::createOne(['name' => 'Example monitor']);
        $token = $this->login($client, 'operator', UserFactory::DEFAULT_PASSWORD);

        $response = $client->request('GET', '/api/monitors', [
            'headers' => [
                'Accept' => self::JSON_API,
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $document = $response->toArray();
        $this->assertArrayHasKey('data', $document);
        $this->assertCount(1, $document['data']);
        $this->assertSame('Example monitor', $document['data'][0]['attributes']['name']);
    }

    public function testMonitorsCollectionWithoutTokenReturnsUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/monitors', [
            'headers' => ['Accept' => self::JSON_API],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMonitorsCollectionWithInvalidTokenReturnsUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/monitors', [
            'headers' => [
                'Accept' => self::JSON_API,
                'Authorization' => 'Bearer not-a-valid-jwt',
            ],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * Log in over HTTP and return the issued JWT.
     */
    private function login(Client $client, string $username, string $password): string
    {
        $response = $client->request('POST', '/auth/login', [
            'json' => ['username' => $username, 'password' => $password],
        ]);

        return $response->toArray()['token'];
    }
}
