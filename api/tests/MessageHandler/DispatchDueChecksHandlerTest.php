<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\DispatchDueChecks;
use App\Message\RunCheck;
use App\MessageHandler\DispatchDueChecksHandler;
use App\Tests\Factory\MonitorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * One tick fans out one RunCheck per due, enabled monitor and skips every monitor
 * that is disabled, not yet due or soft-deleted. The dispatches land on the
 * in-memory async transport, where they are asserted without a worker.
 */
final class DispatchDueChecksHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testItDispatchesExactlyTheDueEnabledMonitors(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable();

        $due = MonitorFactory::createOne(['enabled' => true, 'nextCheckAt' => $now->modify('-30 seconds')]);
        $alsoDue = MonitorFactory::createOne(['enabled' => true, 'nextCheckAt' => $now->modify('-1 second')]);
        MonitorFactory::createOne(['enabled' => false, 'nextCheckAt' => $now->modify('-30 seconds')]);
        MonitorFactory::createOne(['enabled' => true, 'nextCheckAt' => $now->modify('+1 hour')]);
        $deleted = MonitorFactory::createOne(['enabled' => true, 'nextCheckAt' => $now->modify('-30 seconds')]);

        $entityManager = $this->entityManager();
        $entityManager->remove($deleted);
        $entityManager->flush();

        ($this->handler())(new DispatchDueChecks());

        self::assertEqualsCanonicalizing(
            [(string) $due->getId(), (string) $alsoDue->getId()],
            $this->dispatchedMonitorIds(),
        );
    }

    private function handler(): DispatchDueChecksHandler
    {
        $handler = self::getContainer()->get(DispatchDueChecksHandler::class);
        \assert($handler instanceof DispatchDueChecksHandler);

        return $handler;
    }

    /**
     * @return list<string>
     */
    private function dispatchedMonitorIds(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return array_map(
            static function (Envelope $envelope): string {
                $message = $envelope->getMessage();
                \assert($message instanceof RunCheck);

                return $message->monitorId;
            },
            $transport->getSent(),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
