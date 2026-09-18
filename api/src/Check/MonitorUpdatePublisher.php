<?php

declare(strict_types=1);

namespace App\Check;

use App\Entity\Monitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Publishes a "this monitor changed" signal to the Mercure hub, on the monitor's own
 * topic and on the list topic, so a detail page and the dashboard both refresh. The
 * payload is the identifier, the new status and the check time and nothing else: a
 * signal to refresh, never the data itself.
 *
 * A hub that is unreachable is logged as a warning and swallowed — the result is
 * already committed and the hub is not durable state, so a failed publish must never
 * fail the check.
 */
final readonly class MonitorUpdatePublisher
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(Monitor $monitor): void
    {
        $id = (string) $monitor->getId();
        $update = new Update(
            ['pulse://monitors/'.$id, 'pulse://monitors'],
            $this->payload($monitor, $id),
        );

        try {
            $this->hub->publish($update);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->warning('Publishing a monitor update to the Mercure hub failed.', [
                'monitor' => $id,
                'exception' => $exception,
            ]);
        }
    }

    private function payload(Monitor $monitor, string $id): string
    {
        return json_encode([
            'id' => $id,
            'status' => $monitor->getLastStatus()?->value,
            'checkedAt' => $monitor->getLastCheckedAt()?->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR);
    }
}
