<?php

declare(strict_types=1);

namespace App\Check;

use App\Entity\CheckResult;
use App\Entity\Monitor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes one check and denormalises its outcome onto the monitor in a single
 * transaction: the result row, the monitor's last status and last-checked time, and
 * the next due time. The service that performs the write owns every related write —
 * no Doctrine lifecycle hook or entity listener does any of it — and phase 4's
 * incident engine drops into this same transaction rather than restructuring it.
 *
 * next_check_at advances to the moment the check started plus one interval, or, when
 * that already lies in the past (a worker that was down, or a check slower than its
 * own interval), to now plus one interval, so a resumed worker never replays a
 * backlog of ticks.
 */
final readonly class CheckResultRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitorUpdatePublisher $publisher,
    ) {
    }

    public function record(Monitor $monitor, CheckOutcome $outcome, \DateTimeImmutable $checkedAt): void
    {
        $this->entityManager->wrapInTransaction(function () use ($monitor, $outcome, $checkedAt): void {
            $result = (new CheckResult())
                ->setMonitor($monitor)
                ->setStatus($outcome->status)
                ->setLatencyMs($outcome->latencyMs)
                ->setHttpStatusCode($outcome->httpStatusCode)
                ->setErrorMessage($outcome->errorMessage)
                ->setCheckedAt($checkedAt)
                ->setRegion($monitor->getRegion());
            $this->entityManager->persist($result);

            $monitor
                ->setLastStatus($outcome->status)
                ->setLastCheckedAt($checkedAt)
                ->setNextCheckAt($this->nextCheckAt($monitor, $checkedAt));

            // Phase 4 opens or closes the incident here, in the same transaction.

            $this->entityManager->flush();
        });

        // After the commit, never inside it: a subscriber must not be told about a
        // status that then rolls back.
        $this->publisher->publish($monitor);
    }

    private function nextCheckAt(Monitor $monitor, \DateTimeImmutable $checkedAt): \DateTimeImmutable
    {
        $interval = new \DateInterval(\sprintf('PT%dS', $monitor->getIntervalSeconds()));
        $fromSchedule = $checkedAt->add($interval);
        $now = new \DateTimeImmutable();

        if ($fromSchedule < $now) {
            return $now->add($interval);
        }

        return $fromSchedule;
    }
}
