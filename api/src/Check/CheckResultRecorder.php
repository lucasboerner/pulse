<?php

declare(strict_types=1);

namespace App\Check;

use App\Entity\CheckResult;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use App\Incident\IncidentEngine;
use App\Incident\IncidentTransition;
use App\Message\NotifyIncident;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
 * backlog of ticks. A failed check uses the monitor's down interval when it has one,
 * so a recovery is seen without waiting out a long regular interval.
 */
final readonly class CheckResultRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitorUpdatePublisher $publisher,
        private IncidentEngine $incidentEngine,
        private MessageBusInterface $bus,
    ) {
    }

    public function record(Monitor $monitor, CheckOutcome $outcome, \DateTimeImmutable $checkedAt): void
    {
        $transition = null;

        $this->entityManager->wrapInTransaction(function () use ($monitor, $outcome, $checkedAt, &$transition): void {
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
                ->setNextCheckAt($this->nextCheckAt($this->intervalSeconds($monitor, $outcome), $checkedAt));

            $transition = $this->incidentEngine->reconcile($monitor, $outcome, $checkedAt);

            $this->entityManager->flush();
        });

        // After the commit, never inside it: a subscriber must not be told about a
        // status that then rolls back.
        $this->publisher->publish($monitor);

        // Mail is its own message, dispatched only once the check is durably
        // committed, so a rollback sends nothing. The status change an incident
        // transition rides is already carried by the single publish above — this
        // adds no second one.
        if ($transition instanceof IncidentTransition) {
            $this->bus->dispatch(new NotifyIncident(
                (string) $transition->incident->getId(),
                $transition->kind,
            ));
        }
    }

    private function intervalSeconds(Monitor $monitor, CheckOutcome $outcome): int
    {
        if (CheckStatus::Up === $outcome->status) {
            return $monitor->getIntervalSeconds();
        }

        return $monitor->getDownIntervalSeconds() ?? $monitor->getIntervalSeconds();
    }

    private function nextCheckAt(int $intervalSeconds, \DateTimeImmutable $checkedAt): \DateTimeImmutable
    {
        $interval = new \DateInterval(\sprintf('PT%dS', $intervalSeconds));
        $fromSchedule = $checkedAt->add($interval);
        $now = new \DateTimeImmutable();

        if ($fromSchedule < $now) {
            return $now->add($interval);
        }

        return $fromSchedule;
    }
}
