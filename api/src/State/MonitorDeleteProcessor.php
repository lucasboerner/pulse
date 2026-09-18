<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Soft-deletes a monitor by decorating the Doctrine remove processor. Gedmo's
 * SoftDeleteable listener turns the remove into a deletedAt stamp, so the row and
 * all its history survive and the operation returns 204; the monitor then falls
 * out of every read through the global soft-delete filter.
 *
 * @implements ProcessorInterface<Monitor, void>
 */
final readonly class MonitorDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Monitor, void> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
