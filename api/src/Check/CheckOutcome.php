<?php

declare(strict_types=1);

namespace App\Check;

use App\Enum\CheckStatus;

/**
 * The result of running one check, before it becomes a row. A value object, not an
 * entity: it never touches Doctrine, so a strategy stays ignorant of persistence
 * and the recorder owns the mapping onto a CheckResult. Latency and the HTTP code
 * are null when no response arrived.
 */
final readonly class CheckOutcome
{
    public function __construct(
        public CheckStatus $status,
        public ?int $latencyMs,
        public ?int $httpStatusCode,
        public ?string $errorMessage,
    ) {
    }
}
