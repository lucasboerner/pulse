<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Serves the monitor collection by decorating API Platform's Doctrine collection
 * provider. The global Gedmo soft-delete filter already excludes deleted monitors,
 * so this read seam adds no query of its own; shared query logic, when it arrives,
 * belongs in MonitorRepository rather than here.
 *
 * @implements ProviderInterface<Monitor>
 */
final readonly class MonitorCollectionProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Monitor> $collectionProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        return $this->collectionProvider->provide($operation, $uriVariables, $context);
    }
}
