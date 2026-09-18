<?php

declare(strict_types=1);

namespace App\Check;

use App\Enum\MonitorType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the strategy that supports a monitor's type — the first one that claims it.
 * An unsupported type is a wiring mistake, not a runtime condition to swallow, so it
 * throws rather than returning a null strategy for a caller to guard against.
 */
final readonly class CheckStrategyResolver
{
    /**
     * @param iterable<CheckStrategy> $strategies
     */
    public function __construct(
        #[AutowireIterator('app.check_strategy')]
        private iterable $strategies,
    ) {
    }

    public function resolve(MonitorType $type): CheckStrategy
    {
        foreach ($this->strategies as $strategy) {
            if (true === $strategy->supports($type)) {
                return $strategy;
            }
        }

        throw new \RuntimeException(\sprintf('No check strategy supports monitor type "%s".', $type->value));
    }
}
