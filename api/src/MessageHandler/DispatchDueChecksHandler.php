<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DispatchDueChecks;
use App\Message\RunCheck;
use App\Repository\MonitorRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turns one scheduler tick into one RunCheck per due monitor. Due-based rather
 * than tick-based, so a worker that was down resumes cleanly instead of replaying
 * a backlog; the batch is bounded so a single tick can never fan out an unbounded
 * number of messages.
 */
#[AsMessageHandler]
final readonly class DispatchDueChecksHandler
{
    private const int BATCH_LIMIT = 200;

    public function __construct(
        private MonitorRepository $monitors,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(DispatchDueChecks $message): void
    {
        $now = new \DateTimeImmutable();

        foreach ($this->monitors->findDue($now, self::BATCH_LIMIT) as $monitor) {
            $this->bus->dispatch(new RunCheck((string) $monitor->getId()));
        }
    }
}
