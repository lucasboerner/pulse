<?php

declare(strict_types=1);

namespace App\Check;

use App\Entity\Monitor;
use App\Enum\MonitorType;

/**
 * One class per check type, never a branch inside one. A new monitor type is a new
 * enum case and a new strategy: supports() claims the types it runs, run() performs
 * the check and returns its outcome without persisting anything.
 */
interface CheckStrategy
{
    public function supports(MonitorType $type): bool;

    public function run(Monitor $monitor): CheckOutcome;
}
