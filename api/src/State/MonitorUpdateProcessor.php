<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists a monitor update by decorating the Doctrine persist processor. A
 * supplied subscriber list replaces the set — the entity's adder and remover let
 * the serializer diff the collection — while an omitted field leaves it untouched.
 *
 * @implements ProcessorInterface<Monitor, Monitor>
 */
final readonly class MonitorUpdateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Monitor, Monitor> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Monitor
    {
        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
