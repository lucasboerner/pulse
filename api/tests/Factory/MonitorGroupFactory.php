<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\MonitorGroup;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<MonitorGroup>
 */
final class MonitorGroupFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return MonitorGroup::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->words(2, true),
        ];
    }
}
