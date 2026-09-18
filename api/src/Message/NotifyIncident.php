<?php

declare(strict_types=1);

namespace App\Message;

use App\Incident\IncidentTransitionKind;

/**
 * Send the alert mail for one incident transition. Its own message, dispatched only
 * after the check transaction has committed, so an SMTP outage delays alerts and
 * never loses check data, and never blocks the worker's check loop. Carries the
 * incident identifier rather than the row, so a message that outlives its incident
 * stays small and the handler decides against the current state.
 */
final readonly class NotifyIncident
{
    public function __construct(
        public string $incidentId,
        public IncidentTransitionKind $kind,
    ) {
    }
}
