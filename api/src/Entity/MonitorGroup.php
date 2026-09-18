<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\MonitorGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A flat label for the dashboard. Groups are labels: no colour, position or
 * settings; the dashboard sorts them by name.
 *
 * Read-only over the API so a client can resolve a group before referencing it on
 * a monitor. Creating and editing groups waits until the frontend needs it.
 */
#[ORM\Entity(repositoryClass: MonitorGroupRepository::class)]
#[ORM\Table(name: 'monitor_group')]
#[ORM\UniqueConstraint(name: 'uniq_monitor_group_name', columns: ['name'])]
#[ApiResource(
    shortName: 'MonitorGroup',
    operations: [new GetCollection(), new Get()],
    normalizationContext: ['groups' => ['monitor_group:read']],
)]
class MonitorGroup
{
    use EntityIdTrait;
    use TimestampableEntity;

    #[ORM\Column(type: Types::STRING, length: 80, unique: true)]
    #[Groups(['monitor_group:read'])]
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
