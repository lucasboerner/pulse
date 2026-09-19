<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One request to roll up elapsed hours and apply retention. Carries no payload: the
 * work to do is decided against the current tables when the message is handled, not
 * when it is scheduled. Unlike DispatchDueChecks this is routed to the async transport,
 * so the worker drains it and the scheduler consumer is never blocked aggregating and
 * deleting tens of thousands of rows.
 */
final readonly class RollUpChecks
{
}
