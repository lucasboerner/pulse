<?php

declare(strict_types=1);

namespace App\Rollup;

/**
 * The outcome of one rollup run: how many check_rollup rows were written (one per
 * monitor-hour) and raw checks aggregated into them, how many raw rows retention
 * deleted, and the cutoff it used. The console command prints it; the message handler
 * discards it.
 */
final readonly class RollupSummary
{
    public function __construct(
        public int $rollupsWritten,
        public int $rowsAggregated,
        public int $rowsDeleted,
        public ?\DateTimeImmutable $cutoff,
    ) {
    }
}
