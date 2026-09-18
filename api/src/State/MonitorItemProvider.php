<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Serves a single monitor by decorating API Platform's Doctrine item provider. The
 * global Gedmo soft-delete filter makes a deleted monitor resolve to nothing, so a
 * read of one returns 404 without any condition added here.
 *
 * @implements ProviderInterface<Monitor>
 */
final readonly class MonitorItemProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Monitor> $itemProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private ProviderInterface $itemProvider,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        return $this->itemProvider->provide($operation, $uriVariables, $context);
    }
}
