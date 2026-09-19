<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\CheckRollup;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CheckRollup>
 */
final class CheckRollupFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return CheckRollup::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // One all-up hour by default, bucketStart aligned to the top of the current hour
        // in UTC. A test overrides bucketStart and the counts to seed a window; region
        // stays the reserved single value the entity defaults to.
        $bucketStart = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $bucketStart = $bucketStart->setTime((int) $bucketStart->format('H'), 0, 0);

        return [
            'monitor' => MonitorFactory::new(),
            'bucketStart' => $bucketStart,
            'upCount' => 1,
            'degradedCount' => 0,
            'downCount' => 0,
            'latencyMinMs' => 100,
            'latencyAvgMs' => 150,
            'latencyMaxMs' => 200,
            'latencyP95Ms' => 200,
        ];
    }
}
