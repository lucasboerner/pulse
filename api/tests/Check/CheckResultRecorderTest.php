<?php

declare(strict_types=1);

namespace App\Tests\Check;

use App\Check\CheckOutcome;
use App\Check\CheckResultRecorder;
use App\Check\MonitorUpdatePublisher;
use App\Entity\CheckResult;
use App\Enum\CheckStatus;
use App\Tests\Double\CollectingHub;
use App\Tests\Double\ThrowingHub;
use App\Tests\Factory\MonitorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The recorder writes one result, denormalises the monitor and reschedules it in a
 * single transaction, then publishes a refresh signal once the transaction has
 * committed. All of it runs against the database and the in-memory hub double.
 */
final class CheckResultRecorderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testItWritesTheResultAndDenormalisesTheMonitor(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne(['intervalSeconds' => 60]);
        $checkedAt = new \DateTimeImmutable();

        $this->recorder()->record($monitor, new CheckOutcome(CheckStatus::Up, 123, 200, null), $checkedAt);

        $results = $this->entityManager()->getRepository(CheckResult::class)->findBy(['monitor' => $monitor]);
        self::assertCount(1, $results);
        self::assertSame(CheckStatus::Up, $results[0]->getStatus());
        self::assertSame(123, $results[0]->getLatencyMs());
        self::assertSame(200, $results[0]->getHttpStatusCode());
        self::assertSame($monitor->getRegion(), $results[0]->getRegion());

        self::assertSame(CheckStatus::Up, $monitor->getLastStatus());
        self::assertEquals($checkedAt, $monitor->getLastCheckedAt());
        self::assertEquals($checkedAt->modify('+60 seconds'), $monitor->getNextCheckAt());
    }

    public function testAStaleNextCheckIsScheduledFromNowNotTheStaleValue(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne(['intervalSeconds' => 60]);
        $checkedAt = new \DateTimeImmutable('-1 hour');

        $this->recorder()->record($monitor, new CheckOutcome(CheckStatus::Down, null, null, 'unreachable'), $checkedAt);

        $after = new \DateTimeImmutable();
        // checkedAt + 60s lies almost an hour in the past, so the next check must be
        // rescheduled from now, landing at most one interval beyond it.
        self::assertGreaterThan($checkedAt->modify('+60 seconds'), $monitor->getNextCheckAt());
        self::assertLessThanOrEqual($after->modify('+60 seconds'), $monitor->getNextCheckAt());
    }

    public function testItPublishesToBothTopicsWithTheIdentifierAndStatus(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();

        $this->recorder()->record($monitor, new CheckOutcome(CheckStatus::Up, 10, 200, null), new \DateTimeImmutable());

        $updates = $this->hub()->getUpdates();
        self::assertCount(1, $updates);
        $topics = $updates[0]->getTopics();
        self::assertContains('pulse://monitors/'.$monitor->getId(), $topics);
        self::assertContains('pulse://monitors', $topics);

        $data = json_decode($updates[0]->getData(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame((string) $monitor->getId(), $data['id']);
        self::assertSame('up', $data['status']);
    }

    public function testAHubThatThrowsDoesNotFailTheCheck(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $recorder = new CheckResultRecorder(
            $this->entityManager(),
            new MonitorUpdatePublisher(new ThrowingHub(), new NullLogger()),
        );

        $recorder->record($monitor, new CheckOutcome(CheckStatus::Up, 10, 200, null), new \DateTimeImmutable());

        self::assertCount(1, $this->entityManager()->getRepository(CheckResult::class)->findBy(['monitor' => $monitor]));
    }

    private function recorder(): CheckResultRecorder
    {
        $recorder = self::getContainer()->get(CheckResultRecorder::class);
        \assert($recorder instanceof CheckResultRecorder);

        return $recorder;
    }

    private function hub(): CollectingHub
    {
        $hub = self::getContainer()->get(CollectingHub::class);
        \assert($hub instanceof CollectingHub);

        return $hub;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
