<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\CheckResult;
use App\Enum\CheckStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CheckResult>
 */
final class CheckResultFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return CheckResult::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // A passing check by default: a monitor, an up status, a plausible latency
        // and a 200. A test overrides status, latencyMs and checkedAt to seed a
        // window; region stays the reserved single value.
        return [
            'monitor' => MonitorFactory::new(),
            'status' => CheckStatus::Up,
            'latencyMs' => self::faker()->numberBetween(40, 300),
            'httpStatusCode' => 200,
            'checkedAt' => new \DateTimeImmutable(),
        ];
    }
}
