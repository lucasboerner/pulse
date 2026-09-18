<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Monitor;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Subscribes the creating operator, then persists. The Object Mapper's input
 * processor has already mapped the resource onto a Monitor entity by the time this
 * runs, so it receives the entity and adds the operator before the decorated
 * Doctrine persist processor writes the row and its subscriber join rows in one
 * transaction.
 *
 * A client-supplied subscriber list is kept and the creator added to it; a monitor
 * with no other subscribers is still valid — it checks and opens incidents, it just
 * mails nobody.
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
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Monitor
    {
        // The firewall requires a fully authenticated operator here; addSubscriber()
        // dedupes, so a client that also lists the creator changes nothing.
        $operator = $this->security->getUser();
        \assert($operator instanceof User);
        $data->addSubscriber($operator);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
