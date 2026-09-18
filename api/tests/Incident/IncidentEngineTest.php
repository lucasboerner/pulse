<?php

declare(strict_types=1);

namespace App\Tests\Incident;

use App\Check\CheckOutcome;
use App\Enum\CheckStatus;
use App\Enum\IncidentSeverity;
use App\Incident\IncidentEngine;
use App\Incident\IncidentTransitionKind;
use App\Repository\IncidentRepository;
use App\Tests\Factory\MonitorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The engine opens an incident on the first failed check and closes it on the first
 * successful one, and does nothing in between. It persists but never flushes — the
 * tests flush themselves, standing in for the recorder's transaction.
 */
final class IncidentEngineTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAFirstFailureOpensAnIncident(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $checkedAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        $transition = $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Down, null, null, 'Connection timed out.'),
            $checkedAt,
        );

        self::assertNotNull($transition);
        self::assertSame(IncidentTransitionKind::Opened, $transition->kind);
        self::assertEquals($checkedAt, $transition->incident->getStartedAt());
        self::assertSame(IncidentSeverity::Down, $transition->incident->getSeverity());
        self::assertSame('Connection timed out.', $transition->incident->getCause());
        self::assertNull($transition->incident->getEndedAt());

        $this->entityManager()->flush();
        self::assertCount(1, $this->incidents()->findBy(['monitor' => $monitor]));
    }

    public function testTheCauseDescribesTheStatusCodeWhenThereIsNoMessage(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();

        $transition = $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Down, 12, 500, null),
            new \DateTimeImmutable(),
        );

        self::assertNotNull($transition);
        self::assertSame('Unexpected HTTP status code 500.', $transition->incident->getCause());
    }

    public function testASecondConsecutiveFailureChangesNothing(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();

        $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Down, null, null, 'first reason'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
        $this->entityManager()->flush();

        $second = $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Down, null, null, 'second reason'),
            new \DateTimeImmutable('2026-01-01 10:01:00'),
        );
        $this->entityManager()->flush();

        self::assertNull($second);
        $incidents = $this->incidents()->findBy(['monitor' => $monitor]);
        self::assertCount(1, $incidents);
        self::assertSame('first reason', $incidents[0]->getCause());
        self::assertNull($incidents[0]->getEndedAt());
    }

    public function testASuccessClosesTheOpenIncident(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();

        $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Down, null, null, 'unreachable'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
        $this->entityManager()->flush();

        $recoveredAt = new \DateTimeImmutable('2026-01-01 11:00:00');
        $transition = $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Up, 42, 200, null),
            $recoveredAt,
        );

        self::assertNotNull($transition);
        self::assertSame(IncidentTransitionKind::Resolved, $transition->kind);
        self::assertEquals($recoveredAt, $transition->incident->getEndedAt());

        $this->entityManager()->flush();
        self::assertNull($this->incidents()->findOpenFor($monitor));
    }

    public function testASuccessWithoutAnOpenIncidentDoesNothing(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();

        $transition = $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Up, 10, 200, null),
            new \DateTimeImmutable(),
        );
        $this->entityManager()->flush();

        self::assertNull($transition);
        self::assertCount(0, $this->incidents()->findBy(['monitor' => $monitor]));
    }

    public function testADegradedOutcomeMapsToDegradedSeverity(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();

        $transition = $this->engine()->reconcile(
            $monitor,
            new CheckOutcome(CheckStatus::Degraded, 5000, 200, 'Response slower than the timeout budget.'),
            new \DateTimeImmutable(),
        );

        self::assertNotNull($transition);
        self::assertSame(IncidentSeverity::Degraded, $transition->incident->getSeverity());
    }

    private function engine(): IncidentEngine
    {
        $engine = self::getContainer()->get(IncidentEngine::class);
        \assert($engine instanceof IncidentEngine);

        return $engine;
    }

    private function incidents(): IncidentRepository
    {
        $incidents = self::getContainer()->get(IncidentRepository::class);
        \assert($incidents instanceof IncidentRepository);

        return $incidents;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
