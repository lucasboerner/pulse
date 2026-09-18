<?php

declare(strict_types=1);

namespace App\Incident;

use App\Entity\Incident;

/**
 * The one thing a check changed about a monitor's incident state, handed back from
 * the engine to the recorder so the recorder can notify once the transaction has
 * committed. A value object, never persisted: it names the affected incident and how
 * it changed, nothing more.
 */
final readonly class IncidentTransition
{
    public function __construct(
        public Incident $incident,
        public IncidentTransitionKind $kind,
    ) {
    }
}
