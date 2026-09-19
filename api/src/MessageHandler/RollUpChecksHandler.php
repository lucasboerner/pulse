<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RollUpChecks;
use App\Rollup\CheckRollupJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the hourly rollup and retention job. Thin by design, the way RunCheckHandler is:
 * all of the ordering and the guards live in the service.
 */
#[AsMessageHandler]
final readonly class RollUpChecksHandler
{
    public function __construct(
        private CheckRollupJob $job,
    ) {
    }

    public function __invoke(RollUpChecks $message): void
    {
        $this->job->run();
    }
}
