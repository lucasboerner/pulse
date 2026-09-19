<?php

declare(strict_types=1);

namespace App\Command;

use App\Rollup\CheckRollupJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the rollup job synchronously, so it can be verified and an existing database
 * backfilled without waiting for the hourly schedule. Retention runs by default;
 * --no-retention aggregates without deleting any raw rows.
 */
#[AsCommand(
    name: 'app:checks:roll-up',
    description: 'Roll up elapsed hours into check_rollup and delete expired raw results',
)]
final class RollUpChecksCommand extends Command
{
    public function __construct(
        private readonly CheckRollupJob $job,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'retention',
            null,
            InputOption::VALUE_NEGATABLE,
            'Delete raw results behind the retention window after rolling up',
            true,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $withRetention = (bool) $input->getOption('retention');
        $summary = $this->job->run($withRetention);

        $io->success(\sprintf(
            'Wrote %d rollup %s (%d raw %s aggregated).',
            $summary->rollupsWritten,
            1 === $summary->rollupsWritten ? 'row' : 'rows',
            $summary->rowsAggregated,
            1 === $summary->rowsAggregated ? 'check' : 'checks',
        ));

        if ($withRetention) {
            $io->writeln(\sprintf(
                'Deleted %d raw %s%s.',
                $summary->rowsDeleted,
                1 === $summary->rowsDeleted ? 'row' : 'rows',
                null !== $summary->cutoff ? ' older than '.$summary->cutoff->format(\DateTimeInterface::ATOM) : '',
            ));
        } else {
            $io->writeln('Retention skipped (--no-retention).');
        }

        return Command::SUCCESS;
    }
}
