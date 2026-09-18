<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How bad an incident is.
 *
 * Never up: an incident exists only for a failing or degraded monitor. Degraded is
 * reserved alongside CheckStatus::Degraded and emitted by nothing yet.
 */
enum IncidentSeverity: string
{
    case Down = 'down';
    case Degraded = 'degraded';
}
