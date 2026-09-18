<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists a new monitor by decorating the Doctrine persist processor, which
 * writes the row and its subscriber join rows in one transaction.
 *
 * @implements ProcessorInterface<Monitor, Monitor>
 */
final readonly class MonitorCreateProcessor implements ProcessorInterface
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
