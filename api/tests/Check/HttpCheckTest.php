<?php

declare(strict_types=1);

namespace App\Tests\Check;

use App\Check\CheckOutcome;
use App\Check\HttpCheck;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The HTTP check maps a response — or a transport failure — onto an outcome. Every
 * case runs against a MockHttpClient with prepared responses, so the suite makes no
 * network call.
 */
final class HttpCheckTest extends TestCase
{
    public function testATwoHundredIsUpWithALatency(): void
    {
        $outcome = $this->check(new MockHttpClient(new MockResponse('ok', ['http_code' => 200])));

        self::assertSame(CheckStatus::Up, $outcome->status);
        self::assertSame(200, $outcome->httpStatusCode);
        self::assertNotNull($outcome->latencyMs);
        self::assertGreaterThanOrEqual(0, $outcome->latencyMs);
        self::assertNull($outcome->errorMessage);
    }

    public function testAFiveHundredIsDownWithTheCodeRecorded(): void
    {
        $outcome = $this->check(new MockHttpClient(new MockResponse('nope', ['http_code' => 500])));

        self::assertSame(CheckStatus::Down, $outcome->status);
        self::assertSame(500, $outcome->httpStatusCode);
        self::assertNotNull($outcome->errorMessage);
    }

    public function testARedirectIsFollowedAndTheFinalStatusDecides(): void
    {
        // The real client follows redirects; the mock does not, so the final 200 is
        // prepared directly and the request options are captured to prove the check
        // never disables redirect following.
        $capturedOptions = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse('ok', ['http_code' => 200, 'redirect_count' => 1]);
        });

        $outcome = $this->check($client);

        self::assertSame(CheckStatus::Up, $outcome->status);
        self::assertSame(200, $outcome->httpStatusCode);
        self::assertNotSame(0, $capturedOptions['max_redirects'] ?? null);
    }

    public function testAnExpectedStatusMakesAnyOtherStatusDown(): void
    {
        $outcome = $this->check(new MockHttpClient(new MockResponse('ok', ['http_code' => 200])), 301);

        self::assertSame(CheckStatus::Down, $outcome->status);
        self::assertSame(200, $outcome->httpStatusCode);
    }

    public function testATransportFailureIsDownWithAMessageAndNoLatency(): void
    {
        $client = new MockHttpClient(static function (): ResponseInterface {
            throw new TransportException('Could not resolve host example.com');
        });

        $outcome = $this->check($client);

        self::assertSame(CheckStatus::Down, $outcome->status);
        self::assertNull($outcome->latencyMs);
        self::assertNull($outcome->httpStatusCode);
        self::assertSame('Could not resolve host example.com', $outcome->errorMessage);
    }

    public function testALongErrorMessageIsTruncatedToFiveHundredCharacters(): void
    {
        $client = new MockHttpClient(static function (): ResponseInterface {
            throw new TransportException(str_repeat('x', 600));
        });

        $outcome = $this->check($client);

        self::assertNotNull($outcome->errorMessage);
        self::assertSame(500, mb_strlen($outcome->errorMessage));
    }

    private function check(MockHttpClient $client, ?int $expectedStatusCode = null): CheckOutcome
    {
        $monitor = (new Monitor())
            ->setName('Example')
            ->setUrl('https://example.com')
            ->setTimeoutMs(8000)
            ->setExpectedStatusCode($expectedStatusCode);

        return (new HttpCheck($client))->run($monitor);
    }
}
