<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The outcome of a single check, denormalised onto the monitor as its last status.
 *
 * Degraded is reserved: the enum case, the rollup column and the incident severity
 * all have a place for it, but no strategy emits it yet.
 */
enum CheckStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';
}
