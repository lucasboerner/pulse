<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\SortFilter;
use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Entity\Incident;
use App\Enum\IncidentSeverity;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The public shape of an incident, read-only. A DTO over the Incident entity in
 * the same shape as MonitorResource: the read direction reads the entity's #[Map]
 * to hydrate this object, and there is no write side — the incident engine owns
 * every column. The mail-path bookkeeping (notifiedOpenedAt, notifiedResolvedAt)
 * is deliberately absent from the contract.
 *
 * The collection filters by monitor (an IRI, exact match) and defaults to newest
 * first; the sort filter lets a caller reverse that.
 */
#[ApiResource(
    shortName: 'Incident',
    operations: [
        new GetCollection(
            parameters: [
                'monitor' => new QueryParameter(filter: new ExactFilter()),
                'order[:property]' => new QueryParameter(filter: new SortFilter(), properties: ['startedAt']),
            ],
            order: ['startedAt' => 'DESC'],
            stateOptions: new Options(entityClass: Incident::class),
        ),
        new Get(stateOptions: new Options(entityClass: Incident::class)),
    ],
    normalizationContext: ['groups' => ['incident:read']],
)]
#[Map(target: Incident::class)]
class IncidentResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    #[Groups(['incident:read'])]
    public ?\DateTimeImmutable $startedAt = null;

    #[Groups(['incident:read'])]
    public ?\DateTimeImmutable $endedAt = null;

    #[Groups(['incident:read'])]
    public ?IncidentSeverity $severity = null;

    #[Groups(['incident:read'])]
    public ?string $cause = null;

    /**
     * Typed as the resource, not the entity: the Object Mapper follows Monitor's
     * own #[Map] when it hydrates this relation, so it hands over a MonitorResource,
     * which is also what serialises as the JSON:API relationship reference.
     */
    #[Groups(['incident:read'])]
    public ?MonitorResource $monitor = null;
}
