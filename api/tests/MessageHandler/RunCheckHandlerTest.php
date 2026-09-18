<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\CheckResult;
use App\Message\RunCheck;
use App\MessageHandler\RunCheckHandler;
use App\Tests\Factory\MonitorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * A RunCheck for a monitor that has gone missing, been disabled or been soft-deleted
 * since it was dispatched writes nothing and does not fail, so a stale message never
 * poisons the queue. The check itself is exercised elsewhere, without the network.
 */
final class RunCheckHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAnUnknownMonitorWritesNothing(): void
    {
        self::bootKernel();

        ($this->handler())(new RunCheck(Uuid::v7()->toRfc4122()));

        self::assertSame(0, $this->checkResultCount());
    }

    public function testADisabledMonitorWritesNothing(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne(['enabled' => false]);

        ($this->handler())(new RunCheck((string) $monitor->getId()));

        self::assertSame(0, $this->checkResultCount());
    }

    public function testASoftDeletedMonitorWritesNothing(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne(['enabled' => true]);
        $monitorId = (string) $monitor->getId();

        $entityManager = $this->entityManager();
        $entityManager->remove($monitor);
        $entityManager->flush();
        // Clear the identity map so the load goes through the soft-delete filter, as
        // it does in a fresh worker that never saw the monitor.
        $entityManager->clear();

        ($this->handler())(new RunCheck($monitorId));

        self::assertSame(0, $this->checkResultCount());
    }

    private function handler(): RunCheckHandler
    {
        $handler = self::getContainer()->get(RunCheckHandler::class);
        \assert($handler instanceof RunCheckHandler);

        return $handler;
    }

    private function checkResultCount(): int
    {
        return $this->entityManager()->getRepository(CheckResult::class)->count([]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
