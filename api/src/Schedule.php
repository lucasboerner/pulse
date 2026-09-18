<?php

declare(strict_types=1);

namespace App;

use App\Message\DispatchDueChecks;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The application's one recurring schedule. It emits a DispatchDueChecks tick every
 * 15 seconds — short enough to honour the 15-second minimum interval a monitor may
 * carry — and the tick itself stays due-based, so a shorter cadence never dispatches
 * a check before it is due.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(
                RecurringMessage::every('15 seconds', new DispatchDueChecks()),
            )
        ;
    }
}
