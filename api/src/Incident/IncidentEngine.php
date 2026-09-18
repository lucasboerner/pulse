<?php

declare(strict_types=1);

namespace App\Incident;

use App\Check\CheckOutcome;
use App\Entity\Incident;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use App\Enum\IncidentSeverity;
use App\Repository\IncidentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a check result into an outage a human hears about. An incident opens on the
 * first failed check and closes on the first successful one — no debounce, no
 * threshold, no confirmation run: a monitor that hides short outages cannot answer
 * "was it up", the one question the product exists for. The remedy, if flapping ever
 * hurts, is a send-rate cap on the notifier, never a change to detection here.
 *
 * A service the recorder calls from inside its transaction, not a Doctrine listener:
 * the service that writes the check owns the incident write too. It persists a new
 * incident and mutates an open one but never flushes — the recorder's transaction
 * commits both the result and the incident together or neither.
 */
final readonly class IncidentEngine
{
    public function __construct(
        private IncidentRepository $incidents,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Reconcile the monitor's incident state with one check outcome, returning the
     * transition the recorder should notify about after the commit, or null when the
     * outcome changed nothing.
     */
    public function reconcile(Monitor $monitor, CheckOutcome $outcome, \DateTimeImmutable $checkedAt): ?IncidentTransition
    {
        $open = $this->incidents->findOpenFor($monitor);

        if (CheckStatus::Up === $outcome->status) {
            if (null === $open) {
                return null;
            }

            $open->setEndedAt($checkedAt);

            return new IncidentTransition($open, IncidentTransitionKind::Resolved);
        }

        if (null !== $open) {
            return null;
        }

        $incident = (new Incident())
            ->setMonitor($monitor)
            ->setStartedAt($checkedAt)
            ->setSeverity($this->severityFor($outcome->status))
            ->setCause($this->causeFor($outcome));
        $this->entityManager->persist($incident);

        return new IncidentTransition($incident, IncidentTransitionKind::Opened);
    }

    private function severityFor(CheckStatus $status): IncidentSeverity
    {
        return match ($status) {
            CheckStatus::Down => IncidentSeverity::Down,
            CheckStatus::Degraded => IncidentSeverity::Degraded,
            CheckStatus::Up => throw new \LogicException('An incident never opens for an up check.'),
        };
    }

    /**
     * The opening check's own words, kept so the incident outlives the raw result:
     * the error message when there is one, otherwise a plain sentence naming the
     * unexpected status code, truncated to the column's 500 characters.
     */
    private function causeFor(CheckOutcome $outcome): ?string
    {
        $cause = $outcome->errorMessage;

        if (null === $cause && null !== $outcome->httpStatusCode) {
            $cause = \sprintf('Unexpected HTTP status code %d.', $outcome->httpStatusCode);
        }

        if (null === $cause) {
            return null;
        }

        return mb_substr($cause, 0, 500);
    }
}
