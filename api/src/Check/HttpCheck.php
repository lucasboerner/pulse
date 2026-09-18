<?php

declare(strict_types=1);

namespace App\Check;

use App\Entity\Monitor;
use App\Enum\CheckStatus;
use App\Enum\MonitorType;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only strategy in version 1: an HTTP GET whose response status decides up or
 * down. The monitor's timeout bounds both idle time and total duration, so a slow
 * or dead host can never outlive its configured limit and block the worker. No
 * exception escapes as an exception — a connection, DNS or timeout failure is a
 * down outcome carrying the reason, with no latency and no status code.
 *
 * Degraded stays reserved: this strategy emits only up or down.
 */
final readonly class HttpCheck implements CheckStrategy
{
    private const int ERROR_MESSAGE_MAX_LENGTH = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function supports(MonitorType $type): bool
    {
        // A single arm today; a new monitor type makes this match non-exhaustive,
        // and PHPStan fails until the new strategy claims — or explicitly declines —
        // it, so no type is ever silently unsupported.
        return match ($type) {
            MonitorType::Http => true,
        };
    }

    public function run(Monitor $monitor): CheckOutcome
    {
        $timeoutSeconds = $monitor->getTimeoutMs() / 1000;
        $startedAt = microtime(true);

        try {
            $response = $this->httpClient->request('GET', $monitor->getUrl(), [
                'timeout' => $timeoutSeconds,
                'max_duration' => $timeoutSeconds,
            ]);

            // getStatusCode() resolves the response headers, following redirects, so
            // measuring here times the request through the first byte of the reply.
            $statusCode = $response->getStatusCode();
            $latencyMs = $this->elapsedMilliseconds($startedAt);

            return $this->outcomeForStatus($monitor, $statusCode, $latencyMs);
        } catch (TransportExceptionInterface $exception) {
            return new CheckOutcome(
                CheckStatus::Down,
                null,
                null,
                $this->truncate($exception->getMessage()),
            );
        }
    }

    private function outcomeForStatus(Monitor $monitor, int $statusCode, int $latencyMs): CheckOutcome
    {
        $expected = $monitor->getExpectedStatusCode();

        if (null === $expected) {
            $isUp = (200 <= $statusCode && 300 > $statusCode);
        } else {
            $isUp = ($expected === $statusCode);
        }

        if (true === $isUp) {
            return new CheckOutcome(CheckStatus::Up, $latencyMs, $statusCode, null);
        }

        return new CheckOutcome(
            CheckStatus::Down,
            $latencyMs,
            $statusCode,
            $this->truncate(\sprintf('Unexpected HTTP status code %d', $statusCode)),
        );
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function truncate(string $message): string
    {
        return mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH);
    }
}
