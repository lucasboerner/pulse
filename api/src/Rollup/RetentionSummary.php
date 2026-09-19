<?php

declare(strict_types=1);

namespace App\Rollup;

/**
 * The outcome of the retention delete: how many raw rows were removed and the
 * cutoff used. A null cutoff means there was nothing to delete against.
 */
final readonly class RetentionSummary
{
    public function __construct(
        public int $rowsDeleted,
        public ?\DateTimeImmutable $cutoff,
    ) {
    }
}
