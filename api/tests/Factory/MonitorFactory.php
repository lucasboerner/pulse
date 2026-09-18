<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Monitor;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Monitor>
 */
final class MonitorFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Monitor::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // type defaults to MonitorType::Http on the entity; the rest of the
        // schedule columns carry their own defaults.
        return [
            'name' => self::faker()->unique()->words(2, true),
            'url' => self::faker()->url(),
        ];
    }
}
