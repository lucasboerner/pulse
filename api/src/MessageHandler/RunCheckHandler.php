<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Check\CheckResultRecorder;
use App\Check\CheckStrategyResolver;
use App\Message\RunCheck;
use App\Repository\MonitorRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one check for one monitor and records the outcome. A monitor that has gone
 * missing, been soft-deleted or been disabled since the message was dispatched is a
 * no-op logged at debug level, not a failure, so a stale message never poisons the
 * queue — the global soft-delete filter makes a deleted monitor load as null.
 */
#[AsMessageHandler]
final readonly class RunCheckHandler
{
    public function __construct(
        private MonitorRepository $monitors,
        private CheckStrategyResolver $strategies,
        private CheckResultRecorder $recorder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunCheck $message): void
    {
        $monitor = $this->monitors->find($message->monitorId);

        if (null === $monitor || false === $monitor->isEnabled()) {
            $this->logger->debug('Skipped a check for a monitor that is missing, deleted or disabled.', [
                'monitor' => $message->monitorId,
            ]);

            return;
        }

        $checkedAt = new \DateTimeImmutable();
        $outcome = $this->strategies->resolve($monitor->getType())->run($monitor);
        $this->recorder->record($monitor, $outcome, $checkedAt);
    }
}
