<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One scheduler tick. Carries no payload: the due set is computed when the message
 * is handled, not when it is scheduled, so a delayed tick still asks for what is
 * due at handling time. Deliberately unrouted — the scheduler consumer that
 * receives it from the schedule handles it in-process.
 */
final class DispatchDueChecks
{
}
