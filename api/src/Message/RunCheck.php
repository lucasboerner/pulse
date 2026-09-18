<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Run one check for one monitor. Carries only the monitor identifier, so a message
 * that outlives its monitor stays small and the handler decides — against the
 * current row — whether the monitor still needs checking. Routed to the async
 * transport and consumed by the worker.
 */
final readonly class RunCheck
{
    public function __construct(
        public string $monitorId,
    ) {
    }
}
