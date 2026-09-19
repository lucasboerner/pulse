<?php

declare(strict_types=1);

namespace App\Tests\Rollup;

use App\Entity\CheckResult;
use App\Entity\CheckRollup;
use App\Entity\Monitor;
use App\Enum\CheckStatus;
use App\Repository\CheckResultRepository;
use App\Repository\CheckRollupRepository;
use App\Rollup\CheckRollupJob;
use App\Tests\Factory\CheckResultFactory;
use App\Tests\Factory\MonitorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Covers the rollup job's contract: elapsed hours fold into one bucket each with the
 * right counts and latency figures while the current hour stays out; reruns stay
 * idempotent; retention deletes only what is both behind the window and already rolled
 * up; a soft-deleted monitor's history still rolls up; and the catch-up walk is bounded.
 */
final class CheckRollupJobTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testThreeElapsedHoursProduceThreeBucketsAndTheCurrentHourProducesNone(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Bucket three hours back: three timed up checks and one timed-out down check.
        $this->seed($monitor, CheckStatus::Up, 100, $now->modify('-3 hours'));
        $this->seed($monitor, CheckStatus::Up, 200, $now->modify('-3 hours'));
        $this->seed($monitor, CheckStatus::Up, 300, $now->modify('-3 hours'));
        $this->seed($monitor, CheckStatus::Down, null, $now->modify('-3 hours'));
        // Bucket two hours back: one up, one down.
        $this->seed($monitor, CheckStatus::Up, 50, $now->modify('-2 hours'));
        $this->seed($monitor, CheckStatus::Down, null, $now->modify('-2 hours'));
        // Bucket one hour back: every check timed out — non-zero counts, null latencies.
        $this->seed($monitor, CheckStatus::Down, null, $now->modify('-1 hour'));
        $this->seed($monitor, CheckStatus::Down, null, $now->modify('-1 hour'));
        // Current hour, still filling: must not be rolled up.
        $this->seed($monitor, CheckStatus::Up, 999, $now);

        $summary = $this->job()->run(false);

        self::assertSame(3, $summary->rollupsWritten);
        self::assertSame(3, $this->rollupCount());
        self::assertSame([], $this->rollupsFor($this->floorHour($now)), 'The current hour is never rolled up.');

        $threeAgo = $this->rollupsFor($this->floorHour($now->modify('-3 hours')));
        self::assertCount(1, $threeAgo);
        $rollup = $threeAgo[0];
        self::assertSame(3, $rollup->getUpCount());
        self::assertSame(1, $rollup->getDownCount());
        self::assertSame(0, $rollup->getDegradedCount());
        self::assertSame(100, $rollup->getLatencyMinMs());
        self::assertSame(200, $rollup->getLatencyAvgMs());
        self::assertSame(300, $rollup->getLatencyMaxMs());
        // Nearest-rank p95 over [100, 200, 300] is 300.
        self::assertSame(300, $rollup->getLatencyP95Ms());

        $oneAgo = $this->rollupsFor($this->floorHour($now->modify('-1 hour')));
        self::assertCount(1, $oneAgo);
        self::assertSame(2, $oneAgo[0]->getDownCount());
        self::assertSame(0, $oneAgo[0]->getUpCount());
        self::assertNull($oneAgo[0]->getLatencyMinMs());
        self::assertNull($oneAgo[0]->getLatencyAvgMs());
        self::assertNull($oneAgo[0]->getLatencyMaxMs());
        self::assertNull($oneAgo[0]->getLatencyP95Ms());
    }

    public function testRunningTwiceLeavesOneRowPerBucketWithIdenticalValues(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->seed($monitor, CheckStatus::Up, 100, $now->modify('-1 hour'));
        $this->seed($monitor, CheckStatus::Down, null, $now->modify('-2 hours'));
        $this->seed($monitor, CheckStatus::Up, 300, $now->modify('-3 hours'));

        $this->job()->run(false);
        self::assertSame(3, $this->rollupCount());
        $firstRun = $this->rollupsFor($this->floorHour($now->modify('-1 hour')))[0]->getUpCount();

        $this->job()->run(false);
        self::assertSame(3, $this->rollupCount(), 'A second run adds no duplicate rows.');
        self::assertSame(
            $firstRun,
            $this->rollupsFor($this->floorHour($now->modify('-1 hour')))[0]->getUpCount(),
            'A second run leaves the values unchanged.',
        );
    }

    public function testRetentionDeletesRolledUpRawBehindTheWindowAndKeepsNewer(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->seed($monitor, CheckStatus::Up, 100, $now->modify('-8 days'));
        $this->seed($monitor, CheckStatus::Up, 120, $now->modify('-1 hour'));

        $summary = $this->job()->run(true);

        // Both hours rolled up first; then the raw row behind the 7-day window is gone
        // and the recent one survives.
        self::assertSame(2, $this->rollupCount());
        self::assertSame(1, $summary->rowsDeleted);
        self::assertSame(1, $this->checkResultCount());
        $survivor = $this->checkResultRepository()->oldestCheckedAt();
        self::assertNotNull($survivor);
        self::assertGreaterThan($now->modify('-7 days'), $survivor);
    }

    public function testRetentionDeletesNothingBehindAnUnrolledBucket(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Twenty-day-old raw rows and no rollups at all: this is the only copy of that
        // history, so a plain now-minus-seven-days delete would destroy it.
        $this->seed($monitor, CheckStatus::Up, 100, $now->modify('-20 days'));
        $this->seed($monitor, CheckStatus::Up, 110, $now->modify('-20 days')->modify('+5 minutes'));

        $summary = $this->job()->deleteExpiredRaw();

        self::assertSame(0, $summary->rowsDeleted);
        self::assertSame(2, $this->checkResultCount(), 'Nothing behind an unrolled bucket is deleted.');
    }

    public function testASoftDeletedMonitorsRawRowsAreStillAggregated(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $monitorId = (string) $monitor->getId();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->seed($monitor, CheckStatus::Up, 120, $now->modify('-1 hour'));

        // Soft-delete the monitor: Gedmo stamps deletedAt and the global filter hides it
        // from MonitorRepository::find(), but its raw rows remain and retention will
        // still delete them — so they must be rolled up first.
        $entityManager = $this->entityManager();
        $entityManager->remove($monitor);
        $entityManager->flush();
        $entityManager->clear();

        $this->job()->run(false);

        $rollups = $this->rollupsFor($this->floorHour($now->modify('-1 hour')));
        self::assertCount(1, $rollups, "A deleted monitor's history is kept, not lost to retention.");
        self::assertSame($monitorId, (string) $rollups[0]->getMonitor()->getId());
        self::assertSame(1, $rollups[0]->getUpCount());
    }

    public function testTheCatchUpWalkProcessesSeveralBucketsAndStopsAtTheCap(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // 202 consecutive elapsed hours, each with one check — two more than the 200
        // bucket cap, so the walk finishes over two runs.
        for ($hoursAgo = 2; $hoursAgo <= 203; ++$hoursAgo) {
            $this->seed($monitor, CheckStatus::Up, 100, $now->modify("-{$hoursAgo} hours"));
        }

        $first = $this->job()->run(false);
        self::assertSame(200, $first->rollupsWritten, 'One run stops at the 200-bucket cap.');
        self::assertSame(200, $this->rollupCount());

        $second = $this->job()->run(false);
        self::assertSame(2, $second->rollupsWritten, 'The next run finishes the backlog.');
        self::assertSame(202, $this->rollupCount());
    }

    private function seed(Monitor $monitor, CheckStatus $status, ?int $latencyMs, \DateTimeImmutable $checkedAt): void
    {
        CheckResultFactory::createOne([
            'monitor' => $monitor,
            'status' => $status,
            'latencyMs' => $latencyMs,
            'httpStatusCode' => null === $latencyMs ? null : 200,
            'checkedAt' => $checkedAt,
        ]);
    }

    private function floorHour(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $utc = $moment->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), 0, 0);
    }

    /**
     * @return list<CheckRollup>
     */
    private function rollupsFor(\DateTimeImmutable $bucketStart): array
    {
        return $this->rollupRepository()->forBucket($bucketStart);
    }

    private function rollupCount(): int
    {
        return $this->rollupRepository()->count([]);
    }

    private function checkResultCount(): int
    {
        return $this->entityManager()->getRepository(CheckResult::class)->count([]);
    }

    private function job(): CheckRollupJob
    {
        $job = self::getContainer()->get(CheckRollupJob::class);
        \assert($job instanceof CheckRollupJob);

        return $job;
    }

    private function rollupRepository(): CheckRollupRepository
    {
        $repository = self::getContainer()->get(CheckRollupRepository::class);
        \assert($repository instanceof CheckRollupRepository);

        return $repository;
    }

    private function checkResultRepository(): CheckResultRepository
    {
        $repository = self::getContainer()->get(CheckResultRepository::class);
        \assert($repository instanceof CheckResultRepository);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
