<?php

declare(strict_types=1);

namespace App\Incident;

/**
 * What the incident engine just did to an incident, so the notifier knows which mail
 * to send and which stamp makes a retry silent. Only these two: an incident opens on
 * the first failed check and closes on the first successful one, with nothing in
 * between.
 */
enum IncidentTransitionKind: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';
}
