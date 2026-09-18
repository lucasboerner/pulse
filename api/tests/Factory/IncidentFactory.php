<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Incident;
use App\Enum\IncidentSeverity;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Incident>
 */
final class IncidentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Incident::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // An ongoing outage by default: a monitor, a start, a severity and no end.
        // notified_*_at stay null so a test can watch the notifier stamp them.
        return [
            'monitor' => MonitorFactory::new(),
            'startedAt' => new \DateTimeImmutable('-10 minutes'),
            'severity' => IncidentSeverity::Down,
            'cause' => 'Connection timed out.',
        ];
    }
}
